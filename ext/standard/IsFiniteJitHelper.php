<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_finite() NestedJIT helper (#15188, #27021, #27590).
 *
 * Inline IEEE finite check — NestedJIT-safe (no builtin re-entry; deg2rad #27400 shape).
 * Userland JIT still uses {@see JitIsFiniteKernel} via MathIsFinite.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_finite)
 */
final class IsFiniteJitHelper
{
    public static function isFiniteArgv(float $num): bool
    {
        return $num === $num && $num !== \INF && $num !== -\INF;
    }
}
