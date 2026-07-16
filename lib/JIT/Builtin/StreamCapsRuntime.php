<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamCapsKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for stream capability ABI (#11413, #19772).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamCapsKernel}.
 */
final class StreamCapsRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamCapsKernel::ensureLinked($context);
    }

    public static function ensureLocalUriLinked(Context $context): void
    {
        JitStreamCapsKernel::ensureLocalUriLinked($context);
    }

    public static function implement(Context $context): void
    {
        JitStreamCapsKernel::implement($context);
    }
}
