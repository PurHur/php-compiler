<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tan() for compiled JIT/AOT modules (#15088, #27048, #28226, php-in-PHP).
 *
 * NestedJIT-safe fmod-style fold to [-pi, pi] + sin/cos 11-term Horner (#28226).
 * Avoid `\tan` / {@see VmMath::tan} — NestedJIT re-enters MathTan bridge under thin AOT.
 * Avoid early-return if / ternary CFG before Horner — NestedJIT miscompiles that shape.
 * Avoid pack/unpack (#27496). Avoid while-loops (#27838).
 * Exact libc tan for |x|≫2^53 needs the deleted kernel; normal-range matches Zend.
 * php-src: ext/standard/math.c — PHP_FUNCTION(tan)
 */
final class TanJitHelper
{
    public static function tanArgv(float $num): float
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
        $rs = $S11;
        $rs = $S10 + $z * $rs;
        $rs = $S9 + $z * $rs;
        $rs = $S8 + $z * $rs;
        $rs = $S7 + $z * $rs;
        $rs = $S6 + $z * $rs;
        $rs = $S5 + $z * $rs;
        $rs = $S4 + $z * $rs;
        $rs = $S3 + $z * $rs;
        $rs = $S2 + $z * $rs;
        $rs = $S1 + $z * $rs;
        $s = $x + $x * $z * $rs;
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
        $rc = $C11;
        $rc = $C10 + $z * $rc;
        $rc = $C9 + $z * $rc;
        $rc = $C8 + $z * $rc;
        $rc = $C7 + $z * $rc;
        $rc = $C6 + $z * $rc;
        $rc = $C5 + $z * $rc;
        $rc = $C4 + $z * $rc;
        $rc = $C3 + $z * $rc;
        $rc = $C2 + $z * $rc;
        $rc = $C1 + $z * $rc;
        $c = 1.0 + $z * $rc;
        $t = $s / $c;
        // Inf/NaN: num-num is NaN; finite → +0. Preserves NestedJIT-safe straight-line body.
        return $t + ($num - $num);
    }
}
