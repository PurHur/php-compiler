<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Call\ExternalMethod;
use PHPCompiler\JIT\Call\Native;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ErrorHandlerCallbackPolicy;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering helpers for set_error_handler() / restore_error_handler() (#1379, #1492). */
final class JitErrorHandler
{
    /** @var array<string, Value> per-module error-handler shims */
    private static array $handlerShims = [];

    public static function set(
        Context $context,
        JITVariable $callback,
        ?JITVariable $maskArg
    ): Value {
        $name = $callback->compileTimeString ?? null;
        if (null === $name) {
            throw new \LogicException(ErrorHandlerCallbackPolicy::jitRejectionMessage());
        }
        if (!$context->functionIsRegistered($name)) {
            throw new \LogicException(
                "set_error_handler() callback '{$name}' is not a defined function in this compile unit"
            );
        }
        $proxy = $context->resolveFunctionProxy($name);
        if ($proxy instanceof ExternalMethod || !($proxy instanceof Native)) {
            throw new \LogicException(
                "set_error_handler() callback '{$name}' must be a user-defined function in this compile unit"
            );
        }

        $mask = \E_ALL;
        if (null !== $maskArg) {
            $mask = JitLongArg::lower($context, $maskArg, 'set_error_handler() error type mask');
        }

        $i8p = $context->getTypeFromString('int8*');
        $shimFn = self::handlerShim($context, $proxy, $name);
        $handlerFnPtr = $context->builder->pointerCast($shimFn, $i8p);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $sizeT = $context->getTypeFromString('size_t');
        $namePtr = $context->builder->pointerCast($context->constantFromString($name), $i8p);
        $nameLen = $sizeT->constInt(\strlen($name), false);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_set_apply'),
            $ptr,
            $namePtr,
            $nameLen,
            $handlerFnPtr,
            $i32->constInt($mask, false)
        );

        return $ptr;
    }

    public static function restore(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_error_handler_restore_apply'),
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

        $handled = $context->builder->call(
            $userFn->function,
            JitValueBox::pointer($context, $errnoSlot),
            JitValueBox::pointer($context, $errstrSlot),
            JitValueBox::pointer($context, $fileSlot),
            JitValueBox::pointer($context, $lineSlot)
        );
        $asLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $handled
        );
        $truthy = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $asLong,
            $i64->constInt(0, false)
        );
        $context->builder->returnValue(
            $context->builder->zext($truthy, $i32)
        );
        $context->builder->clearInsertionPosition();
        $context->builder->positionAtEnd($resumeBlock);

        self::$handlerShims[$cacheKey] = $shimFn;

        return $shimFn;
    }
}
