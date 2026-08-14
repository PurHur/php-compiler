<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\JitThrow;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;

/**
 * Unified JIT pending-exception buffer for TypeError / ArgumentCountError / ValueError / throw (#5364).
 *
 * Replaces lib/AOT/runtime/phpc_type_error_raise.c — LLVM bodies live in {@see TypeErrorRaise}
 * and {@see JitThrow}; this bridge is the single entry point for MCJIT/AOT wiring.
 */
final class ExceptionBridge
{
    public static function ensureLinked(Context $context): void
    {
        TypeErrorRaise::ensureLinked($context);
        JitThrow::ensureLinked($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        TypeErrorRaise::registerDeclarations($context);
        JitThrow::registerDeclarations($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        TypeErrorRaise::ensureStandaloneBodies($context);
        JitThrow::ensureStandaloneBodies($context);
    }

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        TypeErrorRaise::bindJitEngine($engine);
        JitThrow::bindJitEngine($engine);
    }

    public static function clearPendingAtRunEntry(): void
    {
        TypeErrorRaise::clearPendingAtRunEntry();
        JitThrow::clearPendingAtRunEntry();
    }

    public static function throwPendingIfAny(): void
    {
        TypeErrorRaise::throwPendingIfAny();
        JitThrow::throwPendingIfAny();
    }

    public static function emitClearForStandaloneMain(Context $context): void
    {
        TypeErrorRaise::emitClearForStandaloneMain($context);
    }

    public static function emitAbortIfPendingForStandaloneMain(Context $context): void
    {
        TypeErrorRaise::emitAbortIfPendingForStandaloneMain($context);
    }

    public static function emitTypeError(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
    }

    /**
     * Builtin operand TypeError — catchable in active try/catch, fatal when uncaught (#4564).
     */
    public static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);

            return;
        }

        TypeErrorRaise::emitRaise($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    /**
     * Builtin ArgumentCountError — catchable in active try/catch, fatal when uncaught (#23875).
     */
    public static function emitArgumentCountErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ArgumentCountError', $message);

            return;
        }

        TypeErrorRaise::emitArgumentCountError($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    public static function emitArgumentCountError(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError($context, $message);
    }

    public static function emitValueError(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, $message);
    }

    /**
     * Builtin ValueError — catchable in active try/catch, fatal when uncaught (#28537).
     */
    public static function emitValueErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);

            return;
        }

        TypeErrorRaise::emitValueError($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }

    /**
     * Builtin DateRangeError — catchable in active try/catch, fatal when uncaught (#31118).
     *
     * php-src: ext/date/php_date.c — zend_argument_error(date_ce_date_range_error, …)
     */
    public static function emitDateRangeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            ErrorRaise::ensureStandaloneBodies($context);
        }

        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'DateRangeError', $message);

            return;
        }

        ErrorRaise::emitRaise($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
    }
}
