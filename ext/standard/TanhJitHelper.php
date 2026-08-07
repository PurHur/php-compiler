<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * tanh() for compiled JIT/AOT modules (#15156, #27126, #28459, php-in-PHP).
 *
 * NestedJIT-safe fdlibm e_tanh.c shape with inlined exp (#28459 /
 * peer MathCosh #28446 / MathSinh #28418 / MathExp #28241).
 * Avoid `\tanh` / {@see VmMath::tanh} — NestedJIT re-enters MathTanh bridge under thin AOT.
 * Avoid cross-class Exp/Sinh/Cosh helper call — NestedJIT stubs to 0 (#27017 / Hypot shape).
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * Avoid abs ternary that zeros under NestedJIT — use sqrtPositive(num*num) (asin #28263).
 * php-src: ext/standard/math.c — PHP_FUNCTION(tanh)
 */
final class TanhJitHelper
{
    public static function tanhArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        // tanh(±Inf) = ±1 (before sqrtPositive — Inf magnitude loops forever).
        if ($num === $inf || $num === -$inf) {
            if ($num < 0.0) {
                return -1.0;
            }

            return 1.0;
        }
        if (0.0 === $num) {
            return $num;
        }

        // |num| without ternary abs (NestedJIT zeros `$x < 0 ? -$x : $x` — asin #28263).
        $ax = self::sqrtPositive($num * $num);
        $ex = self::expPositive($ax);
        // NestedJIT: float self-inequality NaN probes are unreliable (always-true) —
        // overflow must gate on Inf identity only (cosh #28446).
        if ($ex === $inf) {
            // |x| large enough that e^|x| overflows → |tanh| = 1.
            if ($num < 0.0) {
                return -1.0;
            }

            return 1.0;
        }

        // tanh(|x|) = (e^|x| − e^−|x|) / (e^|x| + e^−|x|).
        $inv = 1.0 / $ex;
        $y = ($ex - $inv) / ($ex + $inv);

        if ($num < 0.0) {
            return -$y;
        }

        return $y;
    }

    /**
     * NestedJIT-safe exp (inlined from {@see ExpJitHelper} / MathExp #28241).
     * Kept private — do not call ExpJitHelper from this helper unit.
     */
    private static function expPositive(float $num): float
    {
        // Host PHP: NaN / ±Inf. NestedJIT: `$num !== $num` and `=== -$inf` are unreliable;
        // `=== $inf` still catches +Inf under NestedJIT (peer Floor Inf probe).
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
        // Float doubling (not int shift) covers |n| up to 1024 without int overflow.
        $scale = 1.0;
        for ($i = 0; $i < $absN && $i < 1024; ++$i) {
            $scale = $scale + $scale;
        }
        if ($n < 0) {
            $scale = 1.0 / $scale;
        }

        return $y * $scale;
    }

    /**
     * NestedJIT-safe sqrt for non-negative finite args (inlined from {@see SqrtJitHelper} /
     * {@see CoshJitHelper}).
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
