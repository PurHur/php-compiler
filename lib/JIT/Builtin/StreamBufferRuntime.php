<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamBufferKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for stream buffer/chunk/timeout ABI (#14462, #19788).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamBufferKernel}.
 */
final class StreamBufferRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamBufferKernel::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        JitStreamBufferKernel::implement($context);
    }
}
