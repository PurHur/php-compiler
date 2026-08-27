<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\AssertionErrorRaise;
use PHPCompiler\JIT\Builtin\ErrorRaise;

/**
 * Unified JIT pending Error buffer for catchable Error paths (#5373).
 *
 * Replaces lib/AOT/runtime/phpc_error_raise.c — LLVM bodies live in {@see ErrorRaise};
 * readonly property guards use {@see ReadonlyBridge} (#5374).
 * Standalone bodies are lazy via ensureLinked at call sites (#34769 / #35099); Context
 * ensureMinimal and ensureFull no longer eagerly NestedJIT this ABI.
 * compileToFile clear/abort also rely on emit* self-ensure — no Context ensureLinked (#35443).
 */
final class ErrorBridge
{
    public static function ensureLinked(Context $context): void
    {
        AssertionErrorRaise::ensureLinked($context);
        ErrorRaise::ensureLinked($context);
        ReadonlyBridge::ensureLinked($context);
    }

    public static function registerDeclarations(Context $context): void
    {
        AssertionErrorRaise::registerDeclarations($context);
        ErrorRaise::registerDeclarations($context);
        ReadonlyBridge::registerDeclarations($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        AssertionErrorRaise::ensureStandaloneBodies($context);
        ErrorRaise::ensureStandaloneBodies($context);
        ReadonlyBridge::ensureStandaloneBodies($context);
    }

    public static function bindJitEngine(\PHPLLVM\ExecutionEngine $engine): void
    {
        AssertionErrorRaise::bindJitEngine($engine);
        ErrorRaise::bindJitEngine($engine);
        ReadonlyBridge::bindJitEngine($engine);
    }

    public static function clearPendingAtRunEntry(): void
    {
        AssertionErrorRaise::clearPendingAtRunEntry();
        ErrorRaise::clearPendingAtRunEntry();
        ReadonlyBridge::clearPendingAtRunEntry();
    }

    public static function throwPendingIfAny(): void
    {
        AssertionErrorRaise::throwPendingIfAny();
        ErrorRaise::throwPendingIfAny();
        ReadonlyBridge::throwPendingIfAny();
    }

    public static function emitClearForStandaloneMain(Context $context): void
    {
        ErrorRaise::emitClearForStandaloneMain($context);
        ReadonlyBridge::emitClearForStandaloneMain($context);
    }

    public static function emitAbortIfPendingForStandaloneMain(Context $context): void
    {
        ErrorRaise::emitAbortIfPendingForStandaloneMain($context);
        ReadonlyBridge::emitAbortIfPendingForStandaloneMain($context);
    }

    public static function emitError(Context $context, string $message): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, $message);
    }
}
