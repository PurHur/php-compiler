<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitSessionLifecycleKernel;
use PHPCompiler\JIT\Context;

/**
 * LLVM session lifecycle for JIT/AOT (issues #5332, #5750, #6968, #9446, #19896, #33261, #33909).
 *
 * Thin orchestrator — LLVM bodies live in {@see JitSessionLifecycleKernel}.
 * Owns session ABI decls module-locally via {@see declareSessionAbis} /
 * {@see ensureLinked} (#33261 / #33909) so leftover Type empty shells cannot mint
 * session_*.1 (#31894 / #32122). Do not re-add always-on declare in {@see Type}.
 * Replaces lib/AOT/runtime/phpc_session_lifecycle.c. php-src: ext/session/session.c
 */
final class SessionLifecycleRuntime
{
    public static function ensureLinked(Context $context): void
    {
        // Declare before kernel bodies call lookupFunction (#33909 — was Type::register always-on).
        self::declareSessionAbis($context);
        JitSessionLifecycleKernel::ensureLinked($context);
    }

    /**
     * Module-local empty decls when a call site needs lookup before bodies (#33261 / #33909).
     * Not called from {@see Type::register} — owners call this or {@see ensureLinked}.
     * Bodies come from kernel / CreateId / Gc / Encode owners.
     */
    public static function declareSessionAbis(Context $context): void
    {
        JitSessionLifecycleKernel::declareSessionLifecycleAbis($context);
        SessionCreateIdRuntime::declareSessionCreateIdAbis($context);
        SessionGcRuntime::declareSessionGcAbis($context);
        SessionEncodeRuntime::declareSessionEncodeAbis($context);
    }
}
