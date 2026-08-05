<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fmod() for compiled JIT/AOT modules (#15072, #26994, #27838, php-in-PHP).
 *
 * NestedJIT-safe trunc-via-(int) quotient (peer {@see FloorJitHelper} / #27650).
 * Avoid `\fmod` / {@see VmMath::fmod} — NestedJIT re-enters MathFmod bridge under thin AOT.
 * Avoid while-loops — NestedJIT miscompiles float reduction loops under thin AOT (#27838).
 * |q| ≥ 2^53 shares Floor's NestedJIT int64 saturation: peel via float `q` product
 * (often 0 remainder when y·(x/y) rounds to x). Exact libc fmod for huge operands needs
 * the deleted kernel; normal |q|<2^53 matches Zend bit-for-bit.
 * php-src: ext/standard/math.c — PHP_FUNCTION(fmod)
 */
final class FmodJitHelper
{
    public static function fmodArgv(float $num1, float $num2): float
    {
        if ($num1 !== $num1) {
            return $num1;
        }
        if ($num2 !== $num2) {
            return $num2;
        }

        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if (0.0 === $num2 || -0.0 === $num2) {
            return $inf - $inf;
        }
        if ($num1 === $inf || $num1 === -$inf) {
            return $inf - $inf;
        }
        if ($num2 === $inf || $num2 === -$inf) {
            return $num1;
        }

        $q = $num1 / $num2;
        $aq = $q < 0.0 ? -$q : $q;
        // 2^53 via doubling — avoid 9007199254740992.0 literal (#27650).
        $lim = 1.0;
        $lim = $lim * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0; // 2^10
        $lim = $lim * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0; // 2^20
        $lim = $lim * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0; // 2^30
        $lim = $lim * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0; // 2^40
        $lim = $lim * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0 * 2.0; // 2^50
        $lim = $lim * 2.0 * 2.0 * 2.0; // 2^53

        if ($aq < $lim) {
            $t = (float) (int) $q;

            return $num1 - $num2 * $t;
        }

        // Beyond int64-exact range: float quotient product (Floor saturation peer).
        return $num1 - $num2 * $q;
    }
}
