<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\SplAutoloadOutput;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\SplAutoloadCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering helpers for spl_autoload_register() (#1776, #2441). */
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

        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(SplAutoloadCallbackPolicy::jitRejectionMessage());
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
        $shimFn = self::autoloadShim($context, $proxy, $name);
        $fnPtr = $context->builder->pointerCast($shimFn, $i8p);
        $context->builder->call(
            $context->lookupFunction('__phpc_spl_autoload_register_apply'),
            $fnPtr,
            $prepend
        );

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(1, false));

        return JitValueBox::pointer($context, $slot);
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
}
