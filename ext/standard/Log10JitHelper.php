<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log10() for compiled JIT/AOT modules (#15101, #27047, #28642, php-in-PHP).
 *
 * NestedJIT-safe: logPositive / ln(10) (#28642 / peer MathLog #28574 / Log1p #28495).
 * Avoid `\log10` / {@see VmMath::log10} — NestedJIT re-enters MathLog10 bridge under thin AOT.
 * Avoid the former libc log10(3) NestedJIT leaf (deleted with this shrink).
 * Avoid cross-helper NestedJIT calls into log() helper — inline the same peel.
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log10)
 */
final class Log10JitHelper
{
    public static function log10Argv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $nan = $inf - $inf;
        if ($num === $inf) {
            return $inf;
        }
        if ($num === -$inf) {
            return $nan;
        }
        // log10(±0) → −∞ (php-src / libc log10(3)).
        if (0.0 === $num) {
            return -$inf;
        }
        if ($num < 0.0) {
            return $nan;
        }

        // log10(x) = ln(x) / ln(10)
        $ln10 = 2.30258509299404568402;

        return self::logPositive($num) / $ln10;
    }

    /**
     * NestedJIT-safe log for positive finite args (frexp-style scale + 2·atanh series).
     * Same peel as {@see LogJitHelper} / {@see Log1pJitHelper} — kept local to this TU.
     */
    private static function logPositive(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            return $inf;
        }
        if ($num <= 0.0) {
            return $inf - $inf;
        }

        $ln2 = 0.693147180559945309417;
        $x = $num;
        $n = 0;
        // Bring x into [1, 2); 2048 peels cover the full double exponent range.
        for ($i = 0; $i < 2048; ++$i) {
            if ($x >= 2.0) {
                $x *= 0.5;
                $n++;
            } elseif ($x < 1.0) {
                $x *= 2.0;
                $n--;
            } else {
                break;
            }
        }

        // log(x) = 2·atanh((x−1)/(x+1)); |u| ≤ 1/3 on [1, 2).
        $u = ($x - 1.0) / ($x + 1.0);
        $u2 = $u * $u;
        $s = 0.0;
        $term = $u;
        for ($k = 1; $k <= 41; $k += 2) {
            $s += $term / $k;
            $term *= $u2;
        }

        return $n * $ln2 + 2.0 * $s;
    }
}
