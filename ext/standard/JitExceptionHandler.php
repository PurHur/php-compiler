<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionHandlerCallbackPolicy;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\Builtin\ExceptionHandlerJitRuntime;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for set_exception_handler() / restore_exception_handler() (#4311, #3146). */
final class JitExceptionHandler
{
    /** @var array<string, Value> per-module exception-handler shims */
    private static array $handlerShims = [];

    public static function set(Context $context, JITVariable $callback): Value
    {
        ExceptionHandlerJitRuntime::ensureLinked($context);

        if (null !== JitOperandTypeLabel::compileTimeEnumClassName($context, $callback)) {
            throw new \TypeError(ExceptionHandlerCallbackPolicy::invalidCallbackTypeError());
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        if ($callback->isNullConstant) {
            $context->builder->call(
                $context->lookupFunction('__phpc_exception_handler_set_apply'),
                $ptr,
                $i8p->constNull(),
                $sizeT->constInt(0, false),
                $i8p->constNull()
            );

            return $ptr;
        }

        if (!ExceptionHandlerCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(ExceptionHandlerCallbackPolicy::jitRejectionMessage());
        }

        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(ExceptionHandlerCallbackPolicy::jitRejectionMessage());
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \LogicException(
                "set_exception_handler() callback '{$name}' is not a defined function in this compile unit"
            );
        }
        $proxy = $context->resolveFunctionProxy($name);
        if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
            throw new \LogicException(
                "set_exception_handler() callback '{$name}' must be a user-defined function in this compile unit"
            );
        }

        $shimFn = self::handlerShim($context, $proxy, $name);
        $handlerFnPtr = $context->builder->pointerCast($shimFn, $i8p);
        $namePtr = $context->builder->pointerCast($context->constantFromString($name), $i8p);
        $nameLen = $sizeT->constInt(\strlen($name), false);
        $context->builder->call(
            $context->lookupFunction('__phpc_exception_handler_set_apply'),
            $ptr,
            $namePtr,
            $nameLen,
            $handlerFnPtr
        );

        return $ptr;
    }

    public static function restore(Context $context): Value
    {
        ExceptionHandlerJitRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_exception_handler_restore_apply'),
            $ptr
        );

        return $ptr;
    }

    public static function get(Context $context): Value
    {
        ExceptionHandlerJitRuntime::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_exception_handler_get_apply'),
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
        $cacheKey = $moduleKey.'::exh::'.$name;
        if (isset(self::$handlerShims[$cacheKey])) {
            return self::$handlerShims[$cacheKey];
        }

        $i32 = $context->getTypeFromString('int32');
        $objPtr = $context->getTypeFromString('__object__*');
        $cbFnTy = $context->context->functionType($i32, false, $objPtr);

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?: 'fn';
        $shimName = '__ex_handler_shim_'.$safe;
        $shimFn = $context->module->addFunction($shimName, $cbFnTy);
        $context->registerFunction($shimName, $shimFn);

        $resumeBlock = $context->builder->getInsertBlock();
        $entry = $shimFn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $exception = $shimFn->getParam(0);
        $exceptionSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $exceptionSlot),
            $exception
        );

        // Prefer getParam(i) over getParams() — php-llvm Function_::getParams() misses
        // `use llvm\LLVMValueRef_ptr` and fatals under AOT (#21325; peer JitErrorHandler).
        $callArgs = [];
        $paramCount = $userFn->function->countParams();
        for ($index = 0; $index < $paramCount; ++$index) {
            $callArgs[] = self::valueArgForNativeParam(
                $context,
                $exceptionSlot,
                $userFn->function->getParam($index)->typeOf()
            );
        }
        $result = $context->builder->call($userFn->function, ...$callArgs);
        $retTyName = $context->getStringFromType($result->typeOf());
        if ('void' === $retTyName) {
            $context->builder->returnValue($i32->constInt(1, false));
        } else {
            $resultPtr = self::valuePtrFromNativeReturn($context, $result);
            $asLong = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $resultPtr
            );
            $i64 = $context->getTypeFromString('int64');
            $truthy = $context->builder->icmp(
                Builder::INT_NE,
                $asLong,
                $i64->constInt(0, false)
            );
            $context->builder->returnValue($context->builder->zext($truthy, $i32));
        }
        $context->builder->positionAtEnd($resumeBlock);

        self::$handlerShims[$cacheKey] = $shimFn;

        return $shimFn;
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
            'exception handler callback parameter type '.$paramTyName.' is not supported for JIT'
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

        throw new \LogicException(
            'exception handler callback return type '.$retTyName.' is not supported for JIT'
        );
    }
}
