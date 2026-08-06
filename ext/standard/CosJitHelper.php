<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * cos() for compiled JIT/AOT modules (#15087, #27005, #28042, php-in-PHP).
 *
 * NestedJIT-safe fmod-style fold to [-pi, pi] + 11-term even Taylor (#28042).
 * Avoid `\cos` / {@see VmMath::cos} — NestedJIT re-enters MathCos bridge under thin AOT.
 * Avoid early-return if / ternary CFG before Horner — NestedJIT miscompiles that shape.
 * Avoid pack/unpack (#27496). Avoid while-loops (#27838).
 * Exact libc cos for |x|≫2^53 needs the deleted kernel; normal-range matches Zend.
 * php-src: ext/standard/math.c — PHP_FUNCTION(cos)
 */
final class CosJitHelper
{
    public static function cosArgv(float $num): float
    {
        $twoPi = 6.283185307179586;
        $pi = 3.141592653589793;
        $k = $num / $twoPi;
        $x = $num - $twoPi * (float) (int) $k;
        $y = $x + $pi;
        $x = ($y - $twoPi * (float) (int) ($y / $twoPi)) - $pi;
        $z = $x * $x;
        $C1 = -1.0 / 2.0;
        $C2 = 1.0 / 24.0;
        $C3 = -1.0 / 720.0;
        $C4 = 1.0 / 40320.0;
        $C5 = -1.0 / 3628800.0;
        $den = 3628800.0;
        $den = $den * 11.0 * 12.0;
        $C6 = 1.0 / $den;
        $den = $den * 13.0 * 14.0;
        $C7 = -1.0 / $den;
        $den = $den * 15.0 * 16.0;
        $C8 = 1.0 / $den;
        $den = $den * 17.0 * 18.0;
        $C9 = -1.0 / $den;
        $den = $den * 19.0 * 20.0;
        $C10 = 1.0 / $den;
        $den = $den * 21.0 * 22.0;
        $C11 = -1.0 / $den;
        $r = $C11;
        $r = $C10 + $z * $r;
        $r = $C9 + $z * $r;
        $r = $C8 + $z * $r;
        $r = $C7 + $z * $r;
        $r = $C6 + $z * $r;
        $r = $C5 + $z * $r;
        $r = $C4 + $z * $r;
        $r = $C3 + $z * $r;
        $r = $C2 + $z * $r;
        $r = $C1 + $z * $r;
        $c = 1.0 + $z * $r;
        // Inf/NaN: num-num is NaN; finite → +0. Preserves NestedJIT-safe straight-line body.
        return $c + ($num - $num);
    }
}
