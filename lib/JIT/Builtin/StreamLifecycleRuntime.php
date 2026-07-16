<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStreamLifecycleKernel;
use PHPCompiler\JIT\Context;

/**
 * JIT/AOT embed link for stream lifecycle ABI (#9442, #19758).
 *
 * Thin orchestrator — NestedJIT bridges live in {@see JitStreamLifecycleKernel}.
 */
final class StreamLifecycleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitStreamLifecycleKernel::ensureLinked($context);
    }

    /** Real fclose/feof bridges for user-script stream lowering (#9142). */
    public static function ensureLinkedForUserScriptLowering(Context $context): void
    {
        JitStreamLifecycleKernel::ensureLinkedForUserScriptLowering($context);
    }

    public static function implement(Context $context): void
    {
        JitStreamLifecycleKernel::implement($context);
    }

    public static function shouldDeferInventoryEmitStubs(Context $context): bool
    {
        return JitStreamLifecycleKernel::shouldDeferInventoryEmitStubs($context);
    }

    public static function ensureDeferredStubsForInventoryEmit(Context $context): void
    {
        JitStreamLifecycleKernel::ensureDeferredStubsForInventoryEmit($context);
    }

    public static function implementDeferredStubs(Context $context): void
    {
        JitStreamLifecycleKernel::implementDeferredStubs($context);
    }
}
