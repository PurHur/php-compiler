<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hypot() for compiled JIT/AOT modules (#15074, #20664, #27909, php-in-PHP).
 *
 * NestedJIT-safe scale + inlined Newton sqrt (peer MathSqrt #27888 / floor #27650).
 * Avoid `\hypot` / {@see VmMath::hypot} / {@see SqrtJitHelper} — NestedJIT stubs
 * cross-class calls to 0 under thin AOT (#27017 / #27888 shape).
 * Avoid pack/unpack — wrong 0 under thin AOT (#27496). Avoid unbounded float while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(hypot)
 */
final class HypotJitHelper
{
    public static function hypotArgv(float $x, float $y): float
    {
        $inf = 1.0e+308;
        $inf = $inf * $inf;
        $nan = $inf - $inf;

        // IEEE: hypot(±Inf, y) / hypot(x, ±Inf) → +Inf even when the other arg is NaN.
        if ($x === $inf || $x === -$inf || $y === $inf || $y === -$inf) {
            return $inf;
        }
        if ($x !== $x || $y !== $y) {
            return $nan;
        }

        $ax = $x < 0.0 ? -$x : $x;
        $ay = $y < 0.0 ? -$y : $y;
        // (+0,+0) and (−0,−0) → +0 (Zend/libc).
        if (0.0 == $ax) {
            return 0.0 == $ay ? 0.0 : $ay;
        }
        if (0.0 == $ay) {
            return $ax;
        }
        if ($ax < $ay) {
            $t = $ax;
            $ax = $ay;
            $ay = $t;
        }
        $r = $ay / $ax;

        return $ax * self::sqrtPositive(1.0 + $r * $r);
    }

    /**
     * NestedJIT-safe sqrt for positive finite args (inlined from {@see SqrtJitHelper}).
     */
    private static function sqrtPositive(float $num): float
    {
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
