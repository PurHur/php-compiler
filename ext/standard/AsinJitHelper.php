<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * asin() for compiled JIT/AOT modules (#15130, #27016, #28263, php-in-PHP).
 *
 * NestedJIT-safe fdlibm poly for |x|<0.5 + identity asin(x)=π/2−2·asin(√((1−|x|)/2))
 * for |x|≥0.5 (#28263 / peer MathSin #28016 / MathHypot #27909).
 * Avoid `\asin` / {@see VmMath::asin} — NestedJIT re-enters MathAsin bridge under thin AOT.
 * Avoid {@see SqrtJitHelper} cross-class call — NestedJIT stubs to 0 (#27017 / Hypot shape).
 * Avoid ternary abs and `$num < 0.0` sign flips — NestedJIT helper unit.o zeros/skips those
 * branches (asin(−0.5)→0 / asin(−0.9)→−1). Abs via √(x²); sign via `a * (num/ax)`.
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(asin)
 */
final class AsinJitHelper
{
    public static function asinArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $nan = $inf - $inf;
        if ($num === $inf || $num === -$inf) {
            return $nan;
        }

        // NestedJIT-safe |x| (ternary / `$num < 0` abs is unreliable in unit.o).
        $ax = self::sqrtPositive($num * $num);
        if ($ax > 1.0) {
            return $nan;
        }

        $pio2 = 1.57079632679489661923;
        if (1.0 === $ax) {
            // ±π/2 with sign of num (num/ax is ±1).
            return $pio2 * ($num / $ax);
        }

        if ($ax < 0.5) {
            if ($ax < 1.0e-8) {
                return $num;
            }
            // Odd poly in signed num — no separate sign fixup.
            $t = $num * $num;

            return $num + $num * $t * self::asinPoly($t);
        }

        // asin(x) = π/2 − 2·asin(√((1−|x|)/2)); √ arg ∈ [0, 0.25] → poly path.
        $t = (1.0 - $ax) * 0.5;
        $s = self::sqrtPositive($t);
        $as = $s + $s * $t * self::asinPoly($t);
        $a = $pio2 - 2.0 * $as;

        return $a * ($num / $ax);
    }

    /** fdlibm e_asin.c rational approximation for asin on |x|<0.5 (argument z=x²). */
    private static function asinPoly(float $z): float
    {
        $pS0 = 1.66666666666666657415e-01;
        $pS1 = -3.25565818622400915405e-01;
        $pS2 = 2.01212532134875103161e-01;
        $pS3 = -4.00555345006794114027e-02;
        $pS4 = 7.91534994289814532176e-04;
        $pS5 = 3.47933107596021167570e-05;
        $qS1 = -2.40339491173441421878e+00;
        $qS2 = 2.02094576023350569471e+00;
        $qS3 = -6.88283971605453293030e-01;
        $qS4 = 7.70381505559019352791e-02;
        $p = $pS0 + $z * ($pS1 + $z * ($pS2 + $z * ($pS3 + $z * ($pS4 + $z * $pS5))));
        $q = 1.0 + $z * ($qS1 + $z * ($qS2 + $z * ($qS3 + $z * $qS4)));

        return $p / $q;
    }

    /**
     * NestedJIT-safe sqrt for non-negative finite args (inlined from {@see SqrtJitHelper} /
     * {@see HypotJitHelper}).
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
