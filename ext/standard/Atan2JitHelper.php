<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atan2() for compiled JIT/AOT modules (#15102, #27017, #28497, php-in-PHP).
 *
 * NestedJIT-safe: atan(y/x) via Taylor + π/2 complement (#28497).
 * Avoid `\atan2` / {@see VmMath::atan2} / libc atan2 NestedJIT leaf.
 * Avoid Newton |·| peels — NestedJIT AOT of scale loops is flaky (wrong/NaN).
 * Taylor needs no abs-sqrt.
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan2)
 */
final class Atan2JitHelper
{
    public static function atan2Argv(float $y, float $x): float
    {
        if ($y !== $y || $x !== $x) {
            return $y + $x;
        }

        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $pi = 3.14159265358979311600e+00;
        $piO2 = 1.57079632679489655800e+00;
        $piO4 = 7.85398163397448279000e-01;

        $yy = $y * $y;
        $xx = $x * $x;

        if (0.0 == $yy) {
            if (0.0 == $xx) {
                if (!($x < 0.0)) {
                    return $y;
                }

                return ($y < 0.0) ? -$pi : $pi;
            }
            if (!($x < 0.0)) {
                return $y;
            }

            return ($y < 0.0) ? -$pi : $pi;
        }

        if (0.0 == $xx) {
            return ($y < 0.0) ? -$piO2 : $piO2;
        }

        if ($x === $inf || $x === -$inf) {
            if ($y === $inf || $y === -$inf) {
                if (!($x < 0.0) && !($y < 0.0)) {
                    return $piO4;
                }
                if (!($x < 0.0) && ($y < 0.0)) {
                    return -$piO4;
                }
                if (($x < 0.0) && !($y < 0.0)) {
                    return 3.0 * $piO4;
                }

                return -3.0 * $piO4;
            }
            if (!($x < 0.0)) {
                return ($y < 0.0) ? -0.0 : 0.0;
            }

            return ($y < 0.0) ? -$pi : $pi;
        }

        if ($y === $inf || $y === -$inf) {
            return ($y < 0.0) ? -$piO2 : $piO2;
        }

        // |y|==|x| → ±π/4 / ±3π/4 (Taylor converges slowly at |r|=1).
        if ($yy == $xx) {
            if (!($x < 0.0)) {
                return ($y < 0.0) ? -$piO4 : $piO4;
            }

            return ($y < 0.0) ? -3.0 * $piO4 : 3.0 * $piO4;
        }

        $z = self::atanSigned($y / $x);
        if (!($x < 0.0)) {
            return $z;
        }
        if ($y < 0.0) {
            return $z - $pi;
        }

        return $z + $pi;
    }

    /**
     * Signed atan via |r|≤1 reduction + odd Taylor (no sqrt / no for-loop / no if-abs).
     */
    private static function atanSigned(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $piO2 = 1.57079632679489655800e+00;
        if ($num === $inf) {
            return $piO2;
        }
        if ($num === -$inf) {
            return -$piO2;
        }

        $r = $num;
        $r2 = $r * $r;
        $complement = $r2 > 1.0;
        if ($complement) {
            $r = 1.0 / $num;
            $r2 = $r * $r;
        }

        // Odd Taylor Horner in z=r^2 (signed r).
        $a = 0.05252164038014869;
        $a = 0.058823529411764705 - $r2 * $a;
        $a = 0.06666666666666667 - $r2 * $a;
        $a = 0.07692307692307693 - $r2 * $a;
        $a = 0.09090909090909091 - $r2 * $a;
        $a = 0.1111111111111111 - $r2 * $a;
        $a = 0.14285714285714285 - $r2 * $a;
        $a = 0.2 - $r2 * $a;
        $a = 0.3333333333333333 - $r2 * $a;
        $a = 1.0 - $r2 * $a;
        $z = $r * $a;

        if ($complement) {
            if ($num < 0.0) {
                return -$piO2 - $z;
            }

            return $piO2 - $z;
        }

        return $z;
    }
}