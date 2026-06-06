<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\ReadonlyRaise;

/**
 * Unified JIT pending Error buffer for catchable Error / LogicException paths (#5373).
 *
 * Replaces lib/AOT/runtime/phpc_error_raise.c and phpc_readonly_raise.c — LLVM bodies live in
 * {@see ErrorRaise} and {@see ReadonlyRaise}; this bridge is the single entry point for wiring.
 */
final class ErrorBridge
{
    public static function ensureLinked(Context $context): void
    {
        ErrorRaise::ensureLinked($context);
        ReadonlyRaise::ensureLinked($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ReadonlyRaise::registerDeclarations($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        ErrorRaise::ensureStandaloneBodies($context);
        ReadonlyRaise::ensureStandaloneBodies($context);
    }

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        ErrorRaise::bindJitEngine($engine);
        ReadonlyRaise::bindJitEngine($engine);
    }

    public static function clearPendingAtRunEntry(): void
    {
        ErrorRaise::clearPendingAtRunEntry();
        ReadonlyRaise::clearPendingAtRunEntry();
    }

    public static function throwPendingIfAny(): void
    {
        ErrorRaise::throwPendingIfAny();
        ReadonlyRaise::throwPendingIfAny();
    }

    public static function emitClearForStandaloneMain(Context $context): void
    {
        ErrorRaise::emitClearForStandaloneMain($context);
        ReadonlyRaise::emitClearForStandaloneMain($context);
    }

    public static function emitAbortIfPendingForStandaloneMain(Context $context): void
    {
        ErrorRaise::emitAbortIfPendingForStandaloneMain($context);
        ReadonlyRaise::emitAbortIfPendingForStandaloneMain($context);
    }

    public static function emitError(Context $context, string $message): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, $message);
    }
}
