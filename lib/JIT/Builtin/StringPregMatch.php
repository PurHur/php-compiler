<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT body for __compiler_preg_* — embed PHP helper vs standalone LLVM (#5289, #9542, #33187, #33188, #33191, #33192).
 *
 * Delegates to {@see StringPregMatchJit} → {@see PregMatchRuntime} PHP bridge (#13736).
 * Owns `__compiler_preg_match` / `__compiler_preg_match_all` / `__compiler_preg_match_ex` /
 * `__compiler_preg_match_all_ex` / `__compiler_preg_replace` / `__compiler_preg_last_error` (+ `_msg`)
 * module-locally (getNamedFunction first). Do not re-add empty always-on shells in {@see Type} —
 * leftover decls mint preg_match.1 / preg_replace.1 / preg_last_error.1 (#31894 / #32122).
 * {@see ensureLinked} must run before NestedJIT lookup (peer {@see JitPregMatch} / {@see JitPregReplace}).
 *
 * Phase A / M2 spine: bundled in compiler_lib_spine_smoke (not ratio-deferred).
 */
final class StringPregMatch
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        StringPregMatchJit::implement($context);
    }
}
