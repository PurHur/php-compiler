<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * nextafter() for compiled JIT/AOT modules (#15062, #19259, #27496, #28716, php-in-PHP).
 *
 * NestedJIT-safe: ULP peel via frexp-style scale (#28716 / peer MathFpow #28674).
 * Avoid {@see VmMath::nextafter} pack/unpack (#27496 — NestedJIT returns 0 under thin AOT).
 * Avoid the former LLVM bitcast NestedJIT leaf (deleted with this shrink).
 * Avoid unbounded while-loops (#27838).
 * Avoid compound `&&` / `||` conditions — NestedJIT assignOperand bool→double (#28716).
 * php-src: libc nextafter(3) semantics (userland nextafter is a php-src phantom — #28565).
 */
final class NextafterJitHelper
{
    public static function nextafterArgv(float $num, float $next): float
    {
        $inf = 1.0e+308;
        $inf = $inf * $inf;

        if ($num !== $num) {
            return $num;
        }
        if ($next !== $next) {
            return $next;
        }
        if ($num === $next) {
            return $next;
        }

        // min positive subnormal = 2^-1074
        $minPos = 1.0;
        for ($i = 0; $i < 1074; ++$i) {
            $minPos *= 0.5;
        }

        if (0.0 === $num) {
            if ($next > 0.0) {
                return $minPos;
            }

            return -$minPos;
        }

        if ($num === $inf) {
            if ($next < $num) {
                return self::maxFinite();
            }

            return $num;
        }
        if ($num === -$inf) {
            if ($next > $num) {
                return -self::maxFinite();
            }

            return $num;
        }

        $ax = $num < 0.0 ? -$num : $num;
        $smallestNormal = 1.0;
        for ($i = 0; $i < 1022; ++$i) {
            $smallestNormal *= 0.5;
        }

        $towardLarger = $next > $num;
        // Away from zero ↔ same sign as algebraic step for positives.
        $away = ($num > 0.0) === $towardLarger;

        if ($ax < $smallestNormal) {
            $ulp = $minPos;
        } else {
            $m = $ax;
            $exp = 0;
            // Bring |x| into [0.5, 1); 2048 peels cover the full double exponent range.
            for ($i = 0; $i < 2048; ++$i) {
                if ($m >= 1.0) {
                    $m *= 0.5;
                    ++$exp;
                } elseif ($m < 0.5) {
                    $m *= 2.0;
                    --$exp;
                } else {
                    break;
                }
            }
            // Exact power of two (m == 0.5): ULP toward zero is one binade smaller.
            $ue = $exp - 53;
            if (!$away) {
                if (0.5 === $m) {
                    $ue = $exp - 54;
                }
            }
            $ulp = 1.0;
            if ($ue >= 0) {
                for ($i = 0; $i < $ue && $i < 1100; ++$i) {
                    $ulp += $ulp;
                }
            } else {
                for ($i = 0; $i < -$ue && $i < 1200; ++$i) {
                    $ulp *= 0.5;
                }
            }
        }

        if ($towardLarger) {
            return $num + $ulp;
        }

        return $num - $ulp;
    }

    /** max finite = (2 - 2^-52) * 2^1023 — NestedJIT-safe float doubling (no pack). */
    private static function maxFinite(): float
    {
        $max = 1.0;
        for ($i = 0; $i < 1023; ++$i) {
            $max += $max;
        }
        $m = $max;
        $add = $max * 0.5;
        for ($i = 0; $i < 52; ++$i) {
            $m += $add;
            $add *= 0.5;
        }

        return $m;
    }
}
