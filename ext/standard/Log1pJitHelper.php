<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * log1p() for compiled JIT/AOT modules (#15157, #27057, #28495, php-in-PHP).
 *
 * NestedJIT-safe fdlibm s_log1p.c shape: small-x `2·atanh(x/(2+x))` series,
 * else inlined log(1+x) (#28495 / peer MathExpm1 #28487 / Asinh #28355 logPositive).
 * Avoid `\log1p` / {@see VmMath::log1p} — NestedJIT re-enters MathLog1p bridge under thin AOT.
 * Avoid the former libc log1p(3) NestedJIT leaf (deleted with this shrink).
 * Avoid cross-helper NestedJIT calls into log() helper — inline the same peel.
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(log1p)
 */
final class Log1pJitHelper
{
    public static function log1pArgv(float $num): float
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
        if (-1.0 === $num) {
            return -$inf;
        }
        if ($num < -1.0) {
            return $nan;
        }
        if (0.0 === $num) {
            return $num;
        }

        // Outside the small-x neighborhood, log(1+x) via inlined logPositive (fdlibm shape).
        if ($num >= 0.5 || $num <= -0.292893) {
            return self::logPositive(1.0 + $num);
        }

        // log1p(x) = 2·atanh(x/(2+x)); |u| stays modest for |x| below the thresholds above.
        $u = $num / (2.0 + $num);
        $u2 = $u * $u;
        $s = 0.0;
        $term = $u;
        for ($k = 1; $k <= 41; $k += 2) {
            $s += $term / $k;
            $term *= $u2;
        }

        return 2.0 * $s;
    }

    /**
     * NestedJIT-safe log for positive finite args (frexp-style scale + 2·atanh series).
     * Kept private — do not call LogJitHelper from this helper unit.
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
