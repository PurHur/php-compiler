<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sqrt() for compiled JIT/AOT modules (#15115, #20664, #27888, php-in-PHP).
 *
 * NestedJIT-safe scale-into-[0.25,4) + unrolled Newton (peer {@see FloorJitHelper} / #27650,
 * {@see FmodJitHelper} / #27838). Avoid `\sqrt` / {@see VmMath::sqrt} — NestedJIT re-enters
 * MathSqrt bridge under thin AOT. Avoid pack/unpack — wrong 0 under thin AOT (#27496).
 * Avoid unbounded float-reduction while-loops (#27838); bounded scale for is NestedJIT-ok
 * (peer StrrevJitHelper / QuotemetaJitHelper).
 * php-src: ext/standard/math.c — PHP_FUNCTION(sqrt)
 */
final class SqrtJitHelper
{
    public static function sqrtArgv(float $num): float
    {
        if ($num !== $num) {
            return $num;
        }
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            return $num;
        }
        if ($num < 0.0) {
            return $inf - $inf;
        }
        if (0.0 === $num || -0.0 === $num) {
            return $num;
        }

        $x = $num;
        $scale = 1.0;
        // Bring x into [0.25, 4); scale tracks sqrt factor. 600 peels cover full double range.
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
