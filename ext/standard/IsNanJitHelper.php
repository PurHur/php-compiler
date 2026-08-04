<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_nan() NestedJIT helper (#15173, #27021, #27590).
 *
 * Inline IEEE inequality — NestedJIT-safe (no builtin re-entry; deg2rad #27400 shape).
 * Userland JIT still uses {@see JitIsNanKernel} via MathIsNan.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_nan)
 */
final class IsNanJitHelper
{
    public static function isNanArgv(float $num): bool
    {
        return $num !== $num;
    }
}
