<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamLifecycleKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT embed link for stream lifecycle ABI (#9442, #20966, #33073, #33080, #33084, #33088, #33093).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamLifecycleKernel}
 * (no deferred stub fork). Sole owner of `__compiler_fclose` / `__compiler_feof` /
 * `__compiler_fflush` after Type always-on drops (#33088 / #33093 also drop Type
 * always-on is_resource / pclose shells; ABI lives in the kernel).
 */
final class StreamLifecycleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamLifecycleKernel::ensureLinked($context);
    }

    /** Real fclose/feof bridges for user-script stream lowering (#9142, #20966). */
    public static function ensureLinkedForUserScriptLowering(Context $context): void
    {
        JitStreamLifecycleKernel::ensureLinkedForUserScriptLowering($context);
    }

    public static function implement(Context $context): void
    {
        JitStreamLifecycleKernel::implement($context);
    }
}
