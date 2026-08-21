<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamCapsKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for stream capability ABI (#11413, #19772, #33148).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamCapsKernel}.
 * Owns `__compiler_stream_is_local` ABI module-locally: {@see getNamedFunction} first,
 * then {@see addFunction} if absent ({@see JitStreamCapsKernel::implementSingleArgBridge}).
 * Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * stream_is_local.1 (#31894 / #32122).
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
