<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamBufferKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for stream buffer/chunk/timeout ABI (#14462, #19788, #33127, #33134).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamBufferKernel}.
 * Do not re-add empty always-on `__compiler_stream_set_timeout` shells in
 * {@see Type} — leftover decls mint stream_set_timeout.1 (#31894 / #32122).
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
