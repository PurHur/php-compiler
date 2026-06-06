<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

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
}
