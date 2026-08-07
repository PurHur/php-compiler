<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * cosh() for compiled JIT/AOT modules (#15156, #27005, #28446, php-in-PHP).
 *
 * NestedJIT-safe fdlibm e_cosh.c shape with inlined exp (#28446 /
 * peer MathSinh #28418 / MathAtanh #28377 / MathExp #28241).
 * Avoid `\cosh` / {@see VmMath::cosh} — NestedJIT re-enters MathCosh bridge under thin AOT.
 * Avoid cross-class Exp helper call — NestedJIT stubs to 0 (#27017 / Hypot shape).
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * Avoid abs ternary that zeros under NestedJIT — use sqrtPositive(num*num) (asin #28263).
 * php-src: ext/standard/math.c — PHP_FUNCTION(cosh)
 */
final class CoshJitHelper
{
    public static function coshArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf || $num === -$inf) {
            return $inf;
        }
        if (0.0 === $num) {
            return 1.0;
        }

        // |num| without ternary abs (NestedJIT zeros `$x < 0 ? -$x : $x` — asin #28263).
        $ax = self::sqrtPositive($num * $num);
        $ex = self::expPositive($ax);
        // NestedJIT: float self-inequality NaN probes are unreliable (always-true) and
        // forced the half-exp overflow path for finite args (#28446 AOT cosh(1)→0.5·e).
        // Use `=== $inf` only (Floor Inf probe / MathExp #28241).
        if ($ex === $inf) {
            // exp(|x|) overflowed; 0.5·exp(|x|) via half-exp stays finite near DBL_MAX (fdlibm).
            $half = self::expPositive(0.5 * $ax);

            return 0.5 * $half * $half;
        }

        // cosh(|x|) = 0.5 · (e^|x| + e^−|x|) (even — no sign restore).
        // Split reciprocal — keeps NestedJIT from folding the add/div shape oddly.
        $inv = 1.0 / $ex;

        return 0.5 * ($ex + $inv);
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
     * {@see SinhJitHelper}).
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
