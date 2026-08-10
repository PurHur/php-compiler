<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SplAutoloadOutput;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering helpers for spl_autoload_register() / spl_autoload_unregister() (#1776, #2441, #5300, #4744, #3580, #3534). */
final class JitSplAutoload
{
    /** @var array<string, Value> per-module autoload shims */
    private static array $autoloadShims = [];

    public static function register(
        Context $context,
        JITVariable $callback,
        ?JITVariable $prependArg
    ): Value {
        SplAutoloadOutput::ensureLinked($context);

        return self::applyRegister($context, self::resolveShim($context, $callback), $callback, $prependArg);
    }

    public static function callbackSnapshot(Context $context): Value
    {
        SplAutoloadOutput::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $ht = HashTableHelper::alloc($context);
        $depth = SplAutoloadOutput::loadDepth($context);

        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = $context->builder->alloca($sizeT, 1, 'spl_funcs_idx');
        $context->builder->store($zero, $idxSlot);

        $done = BasicBlockHelper::append($context, 'spl_funcs_done');
        $head = BasicBlockHelper::append($context, 'spl_funcs_head');
        $body = BasicBlockHelper::append($context, 'spl_funcs_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $depthSize = $context->builder->zExt($depth, $sizeT);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $depthSize);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $idxI32 = $context->builder->trunc($idx, $i32);
        $metaOpaque = SplAutoloadOutput::loadMetaAt($context, $i32, $idxI32);
        $metaPtr = $context->builder->pointerCast($metaOpaque, $valuePtrTy);
        $elemSlot = JitValueBox::alloc($context);
        $elemPtr = JitValueBox::pointer($context, $elemSlot);
        JitValueBox::copyFromPointer($context, $elemPtr, $metaPtr);
        $elem = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VALUE, $elemPtr);
        HashTableHelper::setAtIndex($context, $ht, $idx, $elem);
        $context->builder->store($context->builder->add($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    public static function unregister(Context $context, JITVariable $callback): Value
    {
        SplAutoloadOutput::ensureLinked($context);

        return self::applyUnregister($context, self::resolveShim($context, $callback));
    }

    public static function dispatchLiteral(Context $context, string $className): void
    {
        SplAutoloadOutput::ensureLinked($context);

        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $namePtr = $context->builder->pointerCast($context->constantFromString($className), $i8p);
        $nameLen = $sizeT->constInt(\strlen($className), false);
        $context->builder->call(
            $context->lookupFunction('__phpc_spl_autoload_dispatch'),
            $namePtr,
            $nameLen
        );
    }

    /** @return Value void sentinel for spl_autoload_call() (#3486). */
    public static function dispatch(Context $context, JITVariable $className): Value
    {
        SplAutoloadOutput::ensureLinked($context);

        // Z_PARAM_STR — Zend stub `$class`; caller strict_types → TypeError on null (#29820).
        $strPtr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $className,
            'spl_autoload_call',
            0,
            'class'
        );
        $map = $context->structFieldMap[$strPtr->typeOf()->getElementType()->getName()];
        $classPtr = $context->builder->load(
            $context->builder->structGep($strPtr, $map['value'])
        );
        $classLen = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__phpc_spl_autoload_dispatch'),
            $context->builder->pointerCast($classPtr, $i8p),
            $context->builder->zExt($classLen, $sizeT)
        );

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    private static function applyRegister(
        Context $context,
        Value $shimFn,
        JITVariable $callback,
        ?JITVariable $prependArg
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $prepend = $i32->constInt(0, false);
        if (null !== $prependArg) {
            if (JITVariable::TYPE_NATIVE_BOOL === $prependArg->type) {
                $prepend = $context->builder->zext($prependArg->nativeValue, $i32);
            } else {
                $prepend = JitLongArg::lower($context, $prependArg, 'spl_autoload_register() prepend flag');
            }
        }

        $i8p = $context->getTypeFromString('int8*');
        $valueTy = $context->getTypeFromString('__value__');
        $valuePtrTy = $context->getTypeFromString('__value__*');
        $metaHeap = $context->memory->malloc($valueTy);
        $metaPtr = $context->builder->pointerCast($metaHeap, $valuePtrTy);
        JitValueBox::assignToPointer($context, $metaPtr, $callback);
        $metaOpaque = $context->builder->pointerCast($metaPtr, $i8p);
        $fnPtr = $context->builder->pointerCast($shimFn, $i8p);
        $context->builder->call(
            $context->lookupFunction('__phpc_spl_autoload_register_apply'),
            $fnPtr,
            $metaOpaque,
            $prepend
        );

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(1, false));

        return JitValueBox::pointer($context, $slot);
    }

    private static function applyUnregister(Context $context, Value $shimFn): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $fnPtr = $context->builder->pointerCast($shimFn, $i8p);
        $found = $context->builder->call(
            $context->lookupFunction('__phpc_spl_autoload_unregister_apply'),
            $fnPtr
        );

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $found,
            $i32->constInt(0, false)
        ));

        return JitValueBox::pointer($context, $slot);
    }

    private static function resolveShim(Context $context, JITVariable $callback): Value
    {
        if (null !== $callback->closureCall) {
            return self::closureAutoloadShim($context, $callback->closureCall);
        }

        $staticName = SplAutoloadCallbackPolicy::compileTimeStaticMethodName($callback);
        if (null !== $staticName) {
            [$className, $methodName] = explode('::', $staticName, 2);
            $proxyName = strtolower($className.'::'.$methodName);
            if (!$context->functionIsRegistered($proxyName)) {
                throw new \LogicException(
                    "spl_autoload_register() callback '{$staticName}' is not a defined static method in this compile unit"
                );
            }
            $proxy = $context->resolveFunctionProxy($proxyName);
            if (!($proxy instanceof Native)) {
                throw new \LogicException(
                    "spl_autoload_register() callback '{$staticName}' must be a user-defined static method in this compile unit"
                );
            }

            return self::autoloadShim($context, $proxy, $staticName);
        }

        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(SplAutoloadCallbackPolicy::jitRejectionMessage());
        }
        if ('spl_autoload' === $name) {
            return self::defaultAutoloadShim($context);
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \LogicException(
                "spl_autoload_register() callback '{$name}' is not a defined function in this compile unit"
            );
        }
        $proxy = $context->resolveFunctionProxy($name);
        if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
            throw new \LogicException(
                "spl_autoload_register() callback '{$name}' must be a user-defined function in this compile unit"
            );
        }

        return self::autoloadShim($context, $proxy, $name);
    }

    private static function autoloadShim(
        Context $context,
        Native $userFn,
        string $name
    ): Value {
        $moduleKey = spl_object_hash($context->module);
        $cacheKey = $moduleKey.'::spl::'.$name;
        if (isset(self::$autoloadShims[$cacheKey])) {
            return self::$autoloadShims[$cacheKey];
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i8p, $sizeT);

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?: 'fn';
        $shimName = '__spl_autoload_shim_'.$safe;
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $classPtr = $shimFn->getParam(0);
        $classLen = $shimFn->getParam(1);
        $i64 = $context->getTypeFromString('int64');

        $classStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->trunc($classLen, $i64),
            $classPtr
        );

        $context->builder->call($userFn->function, $classStr);
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->positionAtEnd($resumeBlock);

        self::$autoloadShims[$cacheKey] = $shimFn;

        return $shimFn;
    }

    private static function defaultAutoloadShim(Context $context): Value
    {
        $moduleKey = spl_object_hash($context->module);
        $cacheKey = $moduleKey.'::spl::default';
        if (isset(self::$autoloadShims[$cacheKey])) {
            return self::$autoloadShims[$cacheKey];
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i8p, $sizeT);

        $shimName = '__spl_autoload_default_shim';
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $classPtr = $shimFn->getParam(0);
        $classLen = $shimFn->getParam(1);
        $i64 = $context->getTypeFromString('int64');
        $classStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->trunc($classLen, $i64),
            $classPtr
        );
        $context->builder->call(
            $context->lookupFunction('spl_autoload'),
            $classStr
        );
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->positionAtEnd($resumeBlock);

        self::$autoloadShims[$cacheKey] = $shimFn;

        return $shimFn;
    }

    private static function closureAutoloadShim(Context $context, Call $call): Value
    {
        $native = self::unwrapNative($call);
        $captures = $call instanceof ClosureWithCaptures ? $call->captureVariables() : [];
        $moduleKey = spl_object_hash($context->module);
        $cacheKey = $moduleKey.'::spl::closure::'.$native->name;
        if (isset(self::$autoloadShims[$cacheKey])) {
            return self::$autoloadShims[$cacheKey];
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i8p, $sizeT);

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $native->name) ?: 'closure';
        $shimName = '__spl_autoload_closure_shim_'.$safe;
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $classPtr = $shimFn->getParam(0);
        $classLen = $shimFn->getParam(1);
        $i64 = $context->getTypeFromString('int64');

        $classStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->trunc($classLen, $i64),
            $classPtr
        );

        $llvmArgs = [$classStr];
        foreach ($captures as $capture) {
            $llvmArgs[] = $context->helper->loadValue($capture);
        }
        $context->builder->call($native->function, ...$llvmArgs);
        $context->builder->returnValue($i32->constInt(1, false));
        $context->builder->positionAtEnd($resumeBlock);

        self::$autoloadShims[$cacheKey] = $shimFn;

        return $shimFn;
    }

    private static function unwrapNative(Call $call): Native
    {
        if ($call instanceof Native) {
            return $call;
        }
        if ($call instanceof ClosureWithCaptures) {
            return $call->innerNative();
        }

        throw new \LogicException(
            'spl_autoload_register() closure callback must be a closure in this compiler build'
        );
    }
}
