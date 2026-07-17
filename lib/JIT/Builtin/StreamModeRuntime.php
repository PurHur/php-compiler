<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamModeKernel;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT link for stream mode ABI via StreamModeJitHelper PHP (#13021, #19794).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamModeKernel}.
 */
final class StreamModeRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamModeKernel::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        JitStreamModeKernel::implement($context);
    }

    public static function emitRegisterMode(Context $context, Value $handle, Value $modeStr): void
    {
        JitStreamModeKernel::emitRegisterMode($context, $handle, $modeStr);
    }

    public static function emitClearMode(Context $context, Value $handle): void
    {
        JitStreamModeKernel::emitClearMode($context, $handle);
    }
}
