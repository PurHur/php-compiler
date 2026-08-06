<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sin() for compiled JIT/AOT modules (#15086, #27048, #28016, php-in-PHP).
 *
 * NestedJIT-safe fmod-style fold to [-pi, pi] + 11-term odd Taylor (#28016).
 * Avoid `\sin` / {@see VmMath::sin} — NestedJIT re-enters MathSin bridge under thin AOT.
 * Avoid early-return if / ternary CFG before Horner — NestedJIT miscompiles that shape.
 * Avoid pack/unpack (#27496). Avoid while-loops (#27838).
 * Exact libc sin for |x|≫2^53 needs the deleted kernel; normal-range matches Zend.
 * php-src: ext/standard/math.c — PHP_FUNCTION(sin)
 */
final class SinJitHelper
{
    public static function sinArgv(float $num): float
    {
        $twoPi = 6.283185307179586;
        $pi = 3.141592653589793;
        $k = $num / $twoPi;
        $x = $num - $twoPi * (float) (int) $k;
        $y = $x + $pi;
        $x = ($y - $twoPi * (float) (int) ($y / $twoPi)) - $pi;
        $z = $x * $x;
        $S1 = -1.0 / 6.0;
        $S2 = 1.0 / 120.0;
        $S3 = -1.0 / 5040.0;
        $S4 = 1.0 / 362880.0;
        $S5 = -1.0 / 39916800.0;
        $den = 39916800.0;
        $den = $den * 12.0 * 13.0;
        $S6 = 1.0 / $den;
        $den = $den * 14.0 * 15.0;
        $S7 = -1.0 / $den;
        $den = $den * 16.0 * 17.0;
        $S8 = 1.0 / $den;
        $den = $den * 18.0 * 19.0;
        $S9 = -1.0 / $den;
        $den = $den * 20.0 * 21.0;
        $S10 = 1.0 / $den;
        $den = $den * 22.0 * 23.0;
        $S11 = -1.0 / $den;
        $r = $S11;
        $r = $S10 + $z * $r;
        $r = $S9 + $z * $r;
        $r = $S8 + $z * $r;
        $r = $S7 + $z * $r;
        $r = $S6 + $z * $r;
        $r = $S5 + $z * $r;
        $r = $S4 + $z * $r;
        $r = $S3 + $z * $r;
        $r = $S2 + $z * $r;
        $r = $S1 + $z * $r;
        $s = $x + $x * $z * $r;
        // Inf/NaN: num-num is NaN; finite → +0. Preserves NestedJIT-safe straight-line body.
        return $s + ($num - $num);
    }
}
