<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atanh() for compiled JIT/AOT modules (#15221, #27058, #28377, php-in-PHP).
 *
 * NestedJIT-safe fdlibm e_atanh.c shape with inlined log (#28377 /
 * peer MathAsinh #28355 / MathAcosh #28331).
 * Avoid `\atanh` / {@see VmMath::atanh} — NestedJIT re-enters MathAtanh bridge under thin AOT.
 * Avoid cross-class Log helper call — NestedJIT stubs to 0 (#27017 / Hypot shape).
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * Avoid abs ternary that zeros under NestedJIT — use sqrtPositive(num*num) (asin #28263).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atanh)
 */
final class AtanhJitHelper
{
    public static function atanhArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $nan = $inf - $inf;

        // |num| without ternary abs (NestedJIT zeros `$x < 0 ? -$x : $x` — asin #28263).
        $ax = self::sqrtPositive($num * $num);
        if ($ax > 1.0) {
            return $nan;
        }
        if (1.0 === $ax) {
            if ($num < 0.0) {
                return -$inf;
            }

            return $inf;
        }
        if (0.0 === $ax) {
            return $num;
        }

        // atanh(x) = 0.5 · log((1+|x|)/(1−|x|)) (fdlibm e_atanh.c).
        $y = 0.5 * self::logPositive((1.0 + $ax) / (1.0 - $ax));
        if ($num < 0.0) {
            return -$y;
        }

        return $y;
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

    /**
     * NestedJIT-safe sqrt for non-negative finite args (inlined from {@see SqrtJitHelper} /
     * {@see HypotJitHelper} / {@see AsinhJitHelper}).
     */
    private static function sqrtPositive(float $num): float
    {
        if (0.0 === $num) {
            return 0.0;
        }
        $x = $num;
        $scale = 1.0;
        for ($i = 0; $i < 600; ++$i) {
            if ($x >= 4.0) {
                $x *= 0.25;
                $scale *= 2.0;
            } elseif ($x > 0.0 && $x < 0.25) {
                $x *= 4.0;
                $scale *= 0.5;
            } else {
                break;
            }
        }

        $y = 0.5 * ($x + 1.0);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);
        $y = 0.5 * ($y + $x / $y);

        return $y * $scale;
    }
}
