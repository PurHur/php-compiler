<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT body for __compiler_preg_* — embed PHP helper vs standalone LLVM (#5289, #9542).
 *
 * Delegates to {@see StringPregMatchJit} → {@see PregMatchRuntime} (+ callback LLVM quarantine).
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
