<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT MCJIT body for __compiler_preg_* — PHP-generated LLVM (issue #5289).
 *
 * Delegates to {@see StringPregMatchJit} (libpcre2-8 via LLVM externals).
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
