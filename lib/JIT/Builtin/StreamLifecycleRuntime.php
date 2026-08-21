<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamLifecycleKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT embed link for stream lifecycle ABI (#9442, #20966, #33073).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamLifecycleKernel}
 * (no deferred stub fork). Sole owner of `__compiler_fclose` after Type always-on drop.
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
