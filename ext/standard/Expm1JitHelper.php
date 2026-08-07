<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * expm1() for compiled JIT/AOT modules (#15157, #27057, #28487, php-in-PHP).
 *
 * NestedJIT-safe ln2 range reduction + Taylor Horner (#28487 / peer MathExp #28241).
 * Avoid `\expm1` / {@see VmMath::expm1} — NestedJIT re-enters MathExpm1 bridge under thin AOT.
 * Avoid the former libc expm1(3) NestedJIT leaf (deleted with this shrink).
 * Avoid cross-helper NestedJIT calls into exp() helper — inline the same peel.
 * Avoid pack/unpack (#27496). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(expm1)
 */
final class Expm1JitHelper
{
    public static function expm1Argv(float $num): float
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
            return -1.0;
        }

        // Identical peel to ExpJitHelper (#28241) — NestedJIT-proven for ±args — then subtract 1.
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
        // Float doubling (not int shift) — NestedJIT-safe (peer Exp #28241).
        $scale = 1.0;
        for ($i = 0; $i < $absN && $i < 1024; ++$i) {
            $scale = $scale + $scale;
        }
        if ($n < 0) {
            $scale = 1.0 / $scale;
        }

        return $y * $scale - 1.0;
    }
}
