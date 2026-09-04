<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ErrorHandlerJitRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithBinding;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Call\NestedClosureInvoke;
use PHPCompiler\JIT\Call\RuntimeIndirectClosureCall;
use PHPCompiler\JIT\ClosureHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ErrorHandlerCallbackPolicy;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedClosureInvokeLlvm;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering helpers for set_error_handler() / restore_error_handler() (#1379, #2456, #1492, #36382).
 *
 * Closures (incl. static + use()-by-ref) lower like {@see JitSplAutoload} — a C ABI shim that
 * rebuilds handler args and invokes the closure body with capture slots. php-src:
 * Zend/zend_builtin_functions.c PHP_FUNCTION(set_error_handler).
 */
final class JitErrorHandler
{
    /** @var array<string, Value> per-module error-handler shims */
    private static array $handlerShims = [];

    private static int $closureShimSeq = 0;

    /**
     * @param Value|null $maskI32 lowered int32 mask, or null for profile-aware E_ALL (#31465)
     */
    public static function set(
        Context $context,
        JITVariable $callback,
        ?Value $maskI32 = null
    ): Value {
        ErrorHandlerJitRuntime::ensureLinked($context);

        if (null !== $callback->closureCall) {
            $shimFn = self::closureHandlerShim($context, $callback, $callback->closureCall);
            $name = '{closure}';
        } else {
            $name = $callback->compileTimeString ?? null;
            if (null === $name) {
                throw new \LogicException(ErrorHandlerCallbackPolicy::jitRejectionMessage());
            }
            if (!$context->functionIsRegistered($name)) {
                throw new \TypeError(ErrorHandlerCallbackPolicy::invalidCallbackTypeError());
            }
            $proxy = $context->resolveFunctionProxy($name);
            if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
                throw new \LogicException(
                    "set_error_handler() callback '{$name}' must be a user-defined function in this compile unit"
                );
            }
            $shimFn = self::handlerShim($context, $proxy, $name);
        }

        $i8p = $context->getTypeFromString('int8*');
        $handlerFnPtr = $context->builder->pointerCast($shimFn, $i8p);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $sizeT = $context->getTypeFromString('size_t');
        $namePtr = $context->builder->pointerCast($context->constantFromString($name), $i8p);
        $nameLen = $sizeT->constInt(\strlen($name), false);
        $i32 = $context->getTypeFromString('int32');
        // Profile-aware E_ALL (30719 on ≥8.4) when mask omitted — not host \E_ALL (#27824).
        $maskVal = null !== $maskI32
            ? $maskI32
            : $i32->constInt(\PHPCompiler\VM\ErrorReporter::eAll(), false);
        $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_set_apply'),
            $ptr,
            $namePtr,
            $nameLen,
            $handlerFnPtr,
            $maskVal
        );

        return $ptr;
    }

    public static function restore(Context $context): Value
    {
        ErrorHandlerJitRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_restore_apply'),
            $ptr
        );

        return $ptr;
    }

    public static function get(Context $context): Value
    {
        ErrorHandlerJitRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_get_apply'),
            $ptr
        );

        return $ptr;
    }

    private static function handlerShim(
        Context $context,
        Native $userFn,
        string $name
    ): Value {
        $moduleKey = spl_object_hash($context->module);
        $cacheKey = $moduleKey.'::err::'.$name;
        if (isset(self::$handlerShims[$cacheKey])) {
            return self::$handlerShims[$cacheKey];
        }

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?: 'fn';
        $shimName = '__err_handler_shim_'.$safe;
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        self::emitHandlerBody($context, $shimFn, $userFn, [], $i32);
        $context->builder->positionAtEnd($resumeBlock);

        self::$handlerShims[$cacheKey] = $shimFn;

        return $shimFn;
    }

    private static function closureHandlerShim(
        Context $context,
        JITVariable $callback,
        Call $call
    ): Value {
        $resolved = self::unwrapToNativeOrCaptures($call);
        if (null !== $resolved) {
            [$native, $captures] = $resolved;
            $moduleKey = spl_object_hash($context->module);
            $cacheKey = $moduleKey.'::err::closure::'.$native->name;
            if (isset(self::$handlerShims[$cacheKey])) {
                return self::$handlerShims[$cacheKey];
            }

            $i32 = $context->getTypeFromString('int32');
            $i8p = $context->getTypeFromString('int8*');
            $sizeT = $context->getTypeFromString('size_t');
            $cbFnTy = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);

            $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $native->name) ?: 'closure';
            $shimName = '__err_handler_closure_shim_'.$safe;
            $shimFn = $context->module->addFunction($shimName, $cbFnTy);
            $context->registerFunction($shimName, $shimFn);

            $resumeBlock = $context->builder->getInsertBlock();
            $entry = $shimFn->appendBasicBlock('entry');
            $context->builder->positionAtEnd($entry);
            self::emitHandlerBody($context, $shimFn, $native, $captures, $i32);
            $context->builder->positionAtEnd($resumeBlock);

            self::$handlerShims[$cacheKey] = $shimFn;

            return $shimFn;
        }

        // Multi-closure modules use RuntimeIndirectClosureCall — store the Closure
        // object in a module global and dispatch via NestedClosureInvoke (#36382 / #24156).
        return self::indirectClosureHandlerShim($context, $callback);
    }

    /**
     * Snapshot Closure object into a module global; shim reloads + NestedClosureInvoke.
     * Needed when many closures share one LLVM module (Slim IncludeHelper graphs).
     */
    private static function indirectClosureHandlerShim(
        Context $context,
        JITVariable $callback
    ): Value {
        NestedClosureInvokeLlvm::ensureLinked($context);

        $seq = ++self::$closureShimSeq;
        $moduleKey = spl_object_hash($context->module);
        $cacheKey = $moduleKey.'::err::indirect::'.$seq;
        if (isset(self::$handlerShims[$cacheKey])) {
            return self::$handlerShims[$cacheKey];
        }

        $objTy = $context->getTypeFromString('__object__*');
        $global = $context->module->addGlobal($objTy, '__err_handler_closure_obj_'.$seq);
        $global->setInitializer($objTy->constNull());
        // Store live Closure at set_error_handler() site (same frame as Nyholm getContents).
        $obj = ClosureHelper::loadObjectFromCallable($context, $callback);
        $context->builder->store($obj, $global);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $cbFnTy = $context->context->functionType($i32, false, $i32, $i8p, $sizeT, $i32);
        $shimName = '__err_handler_indirect_shim_'.$seq;
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $errno = $shimFn->getParam(0);
        $msgPtr = $shimFn->getParam(1);
        $msgLen = $shimFn->getParam(2);
        $line = $shimFn->getParam(3);
        $i64 = $context->getTypeFromString('int64');

        $errnoSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $errnoSlot, $context->builder->sext($errno, $i64));
        $errstrSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $errstrSlot),
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->trunc($msgLen, $i64),
                $msgPtr
            )
        );
        $fileSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $fileSlot)
        );
        $lineSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $lineSlot, $context->builder->sext($line, $i64));

        $loadedObj = $context->builder->load($global);
        $closureSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $closureSlot),
            $loadedObj
        );
        $closureVar = new JITVariable(
            $context,
            JITVariable::TYPE_VALUE,
            JITVariable::KIND_VARIABLE,
            $closureSlot
        );
        $errnoVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $errnoSlot);
        $errstrVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $errstrSlot);
        $fileVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $fileSlot);
        $lineVar = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $lineSlot);

        $handledPtr = (new NestedClosureInvoke())->call(
            $context,
            $closureVar,
            $errnoVar,
            $errstrVar,
            $fileVar,
            $lineVar
        );
        $asLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $handledPtr
        );
        $truthy = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $asLong,
            $i64->constInt(0, false)
        );
        $context->builder->returnValue($context->builder->zext($truthy, $i32));
        $context->builder->positionAtEnd($resumeBlock);

        self::$handlerShims[$cacheKey] = $shimFn;

        return $shimFn;
    }

    /**
     * @return array{0: Native, 1: list<JITVariable>}|null
     */
    private static function unwrapToNativeOrCaptures(Call $call): ?array
    {
        while ($call instanceof ClosureWithBinding) {
            $call = $call->inner();
        }
        if ($call instanceof RuntimeIndirectClosureCall) {
            $candidates = $call->candidates;
            if (1 !== \count($candidates)) {
                return null;
            }
            $only = reset($candidates);
            if (!$only instanceof Call) {
                return null;
            }

            return self::unwrapToNativeOrCaptures($only);
        }
        if ($call instanceof Native) {
            return [$call, []];
        }
        if ($call instanceof ClosureWithCaptures) {
            return [$call->innerNative(), $call->captureVariables()];
        }

        return null;
    }

    /**
     * @param list<JITVariable> $captures
     */
    private static function emitHandlerBody(
        Context $context,
        Value $shimFn,
        Native $userFn,
        array $captures,
        \PHPLLVM\Type $i32
    ): void {
        $errno = $shimFn->getParam(0);
        $msgPtr = $shimFn->getParam(1);
        $msgLen = $shimFn->getParam(2);
        $line = $shimFn->getParam(3);

        $i64 = $context->getTypeFromString('int64');
        $errnoSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $errnoSlot, $context->builder->sext($errno, $i64));
        $errstrSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $errstrSlot),
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $context->builder->trunc($msgLen, $i64),
                $msgPtr
            )
        );
        $fileSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $fileSlot)
        );
        $lineSlot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $lineSlot, $context->builder->sext($line, $i64));

        $handlerSlots = [$errnoSlot, $errstrSlot, $fileSlot, $lineSlot];
        $totalParams = $userFn->function->countParams();
        $captureCount = \count($captures);
        $userArity = max(0, $totalParams - $captureCount);
        $callArgs = [];
        for ($i = 0; $i < $userArity && $i < \count($handlerSlots); ++$i) {
            $callArgs[] = self::valueArgForNativeParam(
                $context,
                $handlerSlots[$i],
                $userFn->function->getParam($i)->typeOf()
            );
        }
        foreach ($captures as $capture) {
            $callArgs[] = $context->helper->loadValue($capture);
        }
        $handled = $context->builder->call($userFn->function, ...$callArgs);
        $handledPtr = self::valuePtrFromNativeReturn($context, $handled);
        $asLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $handledPtr
        );
        $truthy = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $asLong,
            $i64->constInt(0, false)
        );
        $context->builder->returnValue(
            $context->builder->zext($truthy, $i32)
        );
    }

    private static function valueArgForNativeParam(
        Context $context,
        Value $slot,
        \PHPLLVM\Type $paramTy
    ): Value {
        $paramTyName = $context->getStringFromType($paramTy);
        if ('__value__' === $paramTyName) {
            return $context->builder->load($slot);
        }
        if ('__value__*' === $paramTyName) {
            return JitValueBox::pointer($context, $slot);
        }

        throw new \LogicException(
            'error handler callback parameter type '.$paramTyName.' is not supported for JIT'
        );
    }

    private static function valuePtrFromNativeReturn(Context $context, Value $handled): Value
    {
        $retTyName = $context->getStringFromType($handled->typeOf());
        if ('__value__*' === $retTyName) {
            return $handled;
        }
        if ('__value__' === $retTyName) {
            $retSlot = JitValueBox::alloc($context);
            $context->builder->store($handled, $retSlot);

            return JitValueBox::pointer($context, $retSlot);
        }
        // Typed bool/int returns from closures (Nyholm-style handlers return via throw).
        if ('int1' === $retTyName || 'int32' === $retTyName || 'int64' === $retTyName) {
            $retSlot = JitValueBox::alloc($context);
            $i64 = $context->getTypeFromString('int64');
            $asLong = 'int64' === $retTyName
                ? $handled
                : $context->builder->sext($handled, $i64);
            JitValueBox::writeLong($context, $retSlot, $asLong);

            return JitValueBox::pointer($context, $retSlot);
        }
        if ('void' === $retTyName) {
            $retSlot = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $retSlot, $context->getTypeFromString('int64')->constInt(0, false));

            return JitValueBox::pointer($context, $retSlot);
        }

        throw new \LogicException(
            'error handler callback return type '.$retTyName.' is not supported for JIT'
        );
    }
}
