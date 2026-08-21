<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSessionLifecycleKernel;
use PHPCompiler\JIT\Context;

/**
 * LLVM session lifecycle for JIT/AOT (issues #5332, #5750, #6968, #9446, #19896, #33261).
 *
 * Thin orchestrator — LLVM bodies live in {@see JitSessionLifecycleKernel}.
 * Owns Type::register session ABI decls via {@see declareSessionAbis} (#33261) so
 * leftover Type empty shells cannot mint session_*.1 (#31894 / #32122).
 * Replaces lib/AOT/runtime/phpc_session_lifecycle.c. php-src: ext/session/session.c
 */
final class SessionLifecycleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        JitSessionLifecycleKernel::ensureLinked($context);
    }

    /**
     * Module-local empty decls for Type::register (#33261).
     * Bodies come from {@see ensureLinked} / CreateId / Gc / Encode owners.
     */
    public static function declareSessionAbis(Context $context): void
    {
        JitSessionLifecycleKernel::declareSessionLifecycleAbis($context);
        SessionCreateIdRuntime::declareSessionCreateIdAbis($context);
        SessionGcRuntime::declareSessionGcAbis($context);
        SessionEncodeRuntime::declareSessionEncodeAbis($context);
    }
}
