<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSessionLifecycleKernel;
use PHPCompiler\JIT\Context;

/**
 * LLVM session lifecycle for JIT/AOT (issues #5332, #5750, #6968, #9446, #19896).
 *
 * Thin orchestrator — LLVM bodies live in {@see JitSessionLifecycleKernel}.
 * Replaces lib/AOT/runtime/phpc_session_lifecycle.c. php-src: ext/session/session.c
 */
final class SessionLifecycleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitSessionLifecycleKernel::ensureLinked($context);
    }
}
