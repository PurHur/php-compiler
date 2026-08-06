<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * exp() for compiled JIT/AOT modules (#15116, #27047, #28241, php-in-PHP).
 *
 * NestedJIT-safe ln2 range reduction + 20-term Taylor Horner + float 2^|n| peel (#28241).
 * Avoid `\exp` / {@see VmMath::exp} — NestedJIT re-enters MathExp bridge under thin AOT.
 * Avoid pack/unpack (#27496). Avoid ternary self-assign scale (`$s = $n >= 1 ? $s * 2 : $s`) —
 * NestedJIT zeros the result. Avoid large-magnitude threshold compares — NestedJIT mis-folds
 * them for ordinary negatives (Floor #27650). ±Inf/−0 edges: +Inf via constructed Inf identity;
 * −Inf/NaN host paths match Zend; NestedJIT NaN/−Inf compares are unreliable (same class) —
 * normal-range matches Zend. Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(exp)
 */
final class ExpJitHelper
{
    public static function expArgv(float $num): float
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
        // `$scale = $scale + $scale` — NestedJIT-safe (peer Sqrt peel); avoid `*= 0.5` half-chains
        // gated on `$n <= -k` (mis-taken under NestedJIT).
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
