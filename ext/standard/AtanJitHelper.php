<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * atan() for compiled JIT/AOT modules (#15142, #27017, #28470, php-in-PHP).
 *
 * NestedJIT-safe fdlibm s_atan.c shape (#28470 / peer MathAsin #28263 / MathTanh #28459).
 * Avoid `\atan` / {@see VmMath::atan} — NestedJIT re-enters MathAtan bridge under thin AOT.
 * Avoid the former libc atan(3) NestedJIT leaf (deleted with this shrink).
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * Avoid ternary abs — use sqrtPositive(num*num) (asin #28263).
 * php-src: ext/standard/math.c — PHP_FUNCTION(atan)
 */
final class AtanJitHelper
{
    public static function atanArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        // atan(±Inf) = ±π/2 (before abs — Inf magnitude must not enter reduction).
        $atanInfHi = 1.57079632679489655800e+00;
        $atanInfLo = 6.12323399573676603587e-17;
        if ($num === $inf) {
            return $atanInfHi + $atanInfLo;
        }
        if ($num === -$inf) {
            return -($atanInfHi + $atanInfLo);
        }

        // |num| without ternary abs (NestedJIT zeros `$x < 0 ? -$x : $x` — asin #28263).
        $ax = self::sqrtPositive($num * $num);

        // |x| >= 2^66 → ±π/2
        if ($ax >= 73786976294838206464.0) {
            $z = $atanInfHi + $atanInfLo;

            return $z * ($num / $ax);
        }

        $one = 1.0;
        $two = 2.0;
        $id = -1;
        $x = $num;

        if ($ax < 0.4375) {
            // |x| < 2^-29 → return x (inexact raise omitted under NestedJIT).
            if ($ax < 1.862645149230957e-09) {
                return $num;
            }
        } else {
            $x = $ax;
            if ($ax < 1.1875) {
                if ($ax < 0.6875) {
                    // 7/16 <= |x| < 11/16
                    $id = 0;
                    $x = (2.0 * $x - $one) / ($two + $x);
                } else {
                    // 11/16 <= |x| < 19/16
                    $id = 1;
                    $x = ($x - $one) / ($x + $one);
                }
            } elseif ($ax < 2.4375) {
                $id = 2;
                $x = ($x - 1.5) / ($one + 1.5 * $x);
            } else {
                // 2.4375 <= |x| < 2^66
                $id = 3;
                $x = -1.0 / $x;
            }
        }

        $z = $x * $x;
        $w = $z * $z;
        // fdlibm aT[] odd/even Horner (s_atan.c).
        $aT0 = 3.33333333333329318027e-01;
        $aT1 = -1.99999999998764819119e-01;
        $aT2 = 1.42857142725034663711e-01;
        $aT3 = -1.11111104054623557880e-01;
        $aT4 = 9.09089030245300150911e-02;
        $aT5 = -7.69187620562667109101e-02;
        $aT6 = 6.66120958173362126726e-02;
        $aT7 = -5.83357013379066062262e-02;
        $aT8 = 4.97687789280486835225e-02;
        $aT9 = -3.65315727442137357622e-02;
        $aT10 = 1.62858201153657823623e-02;
        $s1 = $z * ($aT0 + $w * ($aT2 + $w * ($aT4 + $w * ($aT6 + $w * ($aT8 + $w * $aT10)))));
        $s2 = $w * ($aT1 + $w * ($aT3 + $w * ($aT5 + $w * ($aT7 + $w * $aT9))));

        if ($id < 0) {
            return $x - $x * ($s1 + $s2);
        }

        // atanhi[] / atanlo[] for id 0..3 (no array index — NestedJIT-safe locals).
        if (0 === $id) {
            $ahi = 4.63647609000806093515e-01;
            $alo = 2.26987774529616870924e-17;
        } elseif (1 === $id) {
            $ahi = 7.85398163397448278999e-01;
            $alo = 3.06161699786838301793e-17;
        } elseif (2 === $id) {
            $ahi = 9.82793723247329054082e-01;
            $alo = 1.39033110312309984516e-17;
        } else {
            $ahi = $atanInfHi;
            $alo = $atanInfLo;
        }
        $z = $ahi - (($x * ($s1 + $s2) - $alo) - $x);

        return $z * ($num / $ax);
    }

    /**
     * NestedJIT-safe sqrt for non-negative finite args (inlined from {@see SqrtJitHelper} /
     * {@see AsinJitHelper}).
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
