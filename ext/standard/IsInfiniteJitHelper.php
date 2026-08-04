<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * is_infinite() NestedJIT helper (#15174, #27021, #27590).
 *
 * Inline ±INF compare — NestedJIT-safe (no builtin re-entry; deg2rad #27400 shape).
 * Userland JIT still uses {@see JitIsInfiniteKernel} via MathIsInfinite.
 * php-src: ext/standard/math.c — PHP_FUNCTION(is_infinite)
 */
final class IsInfiniteJitHelper
{
    public static function isInfiniteArgv(float $num): bool
    {
        return $num === \INF || $num === -\INF;
    }
}
