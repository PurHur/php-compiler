<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * frexp() for compiled JIT/AOT modules (#15201, #22575, #29156, php-in-PHP).
 *
 * NestedJIT-safe: mantissa peel into [0.5, 1) via bounded ×2/÷2 (#29156 /
 * peer MathNextafter #28716). Do not call the shared math frexp helper — floor /
 * log / pow-of-two re-enter math bridges under thin AOT (#27496 class). Avoid pack/unpack.
 * Avoid unbounded while-loops (#27838). Avoid compound `&&` / `||` conditions —
 * NestedJIT assignOperand bool→double (#28716).
 * php-src: frexp(3) / ext/standard/math.c (userland frexp is a php-src phantom — #24133)
 */
final class FrexpJitHelper
{
    private static int $lastExp = 0;

    public static function compute(float $num): float
    {
        self::$lastExp = 0;

        if ($num !== $num) {
            return $num;
        }

        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            return $num;
        }
        if ($num === -$inf) {
            return $num;
        }

        if (0.0 === $num) {
            return $num;
        }

        $ax = $num < 0.0 ? -$num : $num;
        $exp = 0;
        $m = $ax;
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

        self::$lastExp = $exp;
        if ($num < 0.0) {
            return -$m;
        }

        return $m;
    }

    public static function exponent(): int
    {
        return self::$lastExp;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastExp = 0;
    }
}
