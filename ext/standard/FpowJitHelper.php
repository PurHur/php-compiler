<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fpow() / float pow() for compiled JIT/AOT modules (#15189, #19259, #28674, php-in-PHP).
 *
 * NestedJIT-safe: integer peel + logPositive·expOf (#28674 / peer MathLog10 #28642 / Exp #28241).
 * Avoid `\pow` / {@see VmMath::fpow} — NestedJIT re-enters MathFpow bridge under thin AOT.
 * Avoid the former libc pow(3) NestedJIT leaf (deleted with this shrink).
 * Avoid cross-helper NestedJIT calls into log()/exp() helpers — inline the same peels.
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(fpow), pow_function
 */
final class FpowJitHelper
{
    public static function fpowArgv(float $num, float $exponent): float
    {
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $nan = $inf - $inf;

        // IEEE / php-src: pow(x, ±0) → 1 (including NaN base); pow(1, y) → 1 (including NaN exp).
        if (0.0 === $exponent) {
            return 1.0;
        }
        if (1.0 === $num) {
            return 1.0;
        }
        if (1.0 === $exponent) {
            return $num;
        }
        if ($num !== $num || $exponent !== $exponent) {
            return $nan;
        }

        if ($num === $inf) {
            return $exponent > 0.0 ? $inf : 0.0;
        }
        if ($num === -$inf) {
            if ($exponent > -2147483648.0 && $exponent < 2147483648.0) {
                $ei = (int) $exponent;
                if ($exponent === (float) $ei) {
                    return self::powByInt($num, $ei);
                }
            }

            return $nan;
        }

        if (0.0 === $num) {
            return $exponent > 0.0 ? 0.0 : $inf;
        }

        if ($exponent === $inf) {
            $ax = $num < 0.0 ? -$num : $num;
            if ($ax > 1.0) {
                return $inf;
            }
            if ($ax < 1.0) {
                return 0.0;
            }

            // |x| == 1 → 1 (php-src / libc: pow(±1, ±Inf)).
            return 1.0;
        }
        if ($exponent === -$inf) {
            $ax = $num < 0.0 ? -$num : $num;
            if ($ax > 1.0) {
                return 0.0;
            }
            if ($ax < 1.0) {
                return $inf;
            }

            return 1.0;
        }

        // Exact integer exponents (incl. negative bases) via successive squaring.
        if ($exponent > -2147483648.0 && $exponent < 2147483648.0) {
            $ei = (int) $exponent;
            if ($exponent === (float) $ei) {
                return self::powByInt($num, $ei);
            }
        }

        if ($num > 0.0) {
            return self::expOf($exponent * self::logPositive($num));
        }

        // Negative base + non-integer exponent → NaN (php-src / libc).
        return $nan;
    }

    /** Successive-squaring integer power — NestedJIT-safe (no bit ops). */
    private static function powByInt(float $base, int $exp): float
    {
        if (0 === $exp) {
            return 1.0;
        }
        $neg = $exp < 0;
        $e = $neg ? -$exp : $exp;
        $result = 1.0;
        $b = $base;
        for ($i = 0; $i < 64; ++$i) {
            if ($e <= 0) {
                break;
            }
            $half = (int) ($e / 2);
            if ($e !== $half + $half) {
                $result *= $b;
            }
            $b *= $b;
            $e = $half;
        }

        return $neg ? 1.0 / $result : $result;
    }

    /**
     * NestedJIT-safe log for positive finite args (frexp-style scale + 2·atanh series).
     * Same peel as {@see LogJitHelper} / {@see Log10JitHelper} — kept local to this TU.
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
     * NestedJIT-safe exp (ln2 reduction + Horner + float 2^|n| peel).
     * Same peel as {@see ExpJitHelper} — kept local to this TU.
     */
    private static function expOf(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            return $inf;
        }
        if ($num === -$inf) {
            return 0.0;
        }

        $ln2 = 0.693147180559945309417;
        $invLn2 = 1.44269504088896340736;
        $k = $num * $invLn2;
        $n = (int) ($k + ($k >= 0.0 ? 0.5 : -0.5));
        $r = $num - $n * $ln2;

        // exp(r) via nested Horner (20 terms; |r| ≤ ln2/2 after reduction).
        $y = 1.0;
        $y = 1.0 + $r * $y / 20.0;
        $y = 1.0 + $r * $y / 19.0;
        $y = 1.0 + $r * $y / 18.0;
        $y = 1.0 + $r * $y / 17.0;
        $y = 1.0 + $r * $y / 16.0;
        $y = 1.0 + $r * $y / 15.0;
        $y = 1.0 + $r * $y / 14.0;
        $y = 1.0 + $r * $y / 13.0;
        $y = 1.0 + $r * $y / 12.0;
        $y = 1.0 + $r * $y / 11.0;
        $y = 1.0 + $r * $y / 10.0;
        $y = 1.0 + $r * $y / 9.0;
        $y = 1.0 + $r * $y / 8.0;
        $y = 1.0 + $r * $y / 7.0;
        $y = 1.0 + $r * $y / 6.0;
        $y = 1.0 + $r * $y / 5.0;
        $y = 1.0 + $r * $y / 4.0;
        $y = 1.0 + $r * $y / 3.0;
        $y = 1.0 + $r * $y / 2.0;
        $y = 1.0 + $r * $y / 1.0;

        $absN = $n;
        if ($n < 0) {
            $absN = -$n;
        }
        $scale = 1.0;
        for ($i = 0; $i < $absN && $i < 1024; ++$i) {
            $scale = $scale + $scale;
        }
        if ($n < 0) {
            $scale = 1.0 / $scale;
        }

        return $y * $scale;
    }
}
