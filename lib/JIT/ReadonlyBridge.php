<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ReadonlyRaise;

/**
 * JIT pending Error buffer for readonly property write guards (#5374).
 *
 * Replaces lib/AOT/runtime/phpc_readonly_raise.c — LLVM bodies live in {@see ReadonlyRaise};
 * this bridge is the entry point for MCJIT/AOT wiring (paired with {@see ErrorBridge}).
 */
final class ReadonlyBridge
{
    public static function ensureLinked(Context $context): void
    {
        ReadonlyRaise::ensureLinked($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        ReadonlyRaise::registerDeclarations($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        ReadonlyRaise::ensureStandaloneBodies($context);
    }

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        ReadonlyRaise::bindJitEngine($engine);
    }

    public static function clearPendingAtRunEntry(): void
    {
        ReadonlyRaise::clearPendingAtRunEntry();
    }

    public static function throwPendingIfAny(): void
    {
        ReadonlyRaise::throwPendingIfAny();
    }

    public static function emitClearForStandaloneMain(Context $context): void
    {
        ReadonlyRaise::emitClearForStandaloneMain($context);
    }

    public static function emitAbortIfPendingForStandaloneMain(Context $context): void
    {
        ReadonlyRaise::emitAbortIfPendingForStandaloneMain($context);
    }

    public static function emitReadonlyViolation(Context $context, string $message): void
    {
        ReadonlyRaise::registerDeclarations($context);
        ReadonlyRaise::ensureLinked($context);
        ReadonlyRaise::emitRaise($context, $message);
    }
}
