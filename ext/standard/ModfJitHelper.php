<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * modf() for compiled JIT/AOT modules (#15200, #22519, #29244, php-in-PHP).
 *
 * NestedJIT-safe: integer-part trunc toward −∞ (positive) / +∞ (negative) via
 * Floor/Ceil #27650 peel shape (#29244 / peer MathFrexp #29156). Do not call
 * the shared VmMath modf helper — floor / ceil re-enter math bridges under thin
 * AOT (#27496 class). Avoid `\floor` / `\ceil` / `\is_nan` / `\is_infinite`.
 * Avoid compound `&&` / `||` conditions — NestedJIT assignOperand bool→double
 * (#28716). Avoid unbounded while-loops (#27838).
 * php-src: ext/standard/math.c — PHP_FUNCTION(modf)
 * Userland modf() is a php-src phantom and was unregistered (#25359).
 */
final class ModfJitHelper
{
    private static float $lastIntPart = 0.0;

    public static function compute(float $num): float
    {
        if ($num !== $num) {
            self::$lastIntPart = $num;

            return $num;
        }

        $inf = 1.0e+308;
        $inf = $inf * $inf;
        if ($num === $inf) {
            self::$lastIntPart = $num;

            return $num;
        }
        if ($num === -$inf) {
            self::$lastIntPart = $num;

            return $num;
        }

        if (0.0 === $num) {
            // Preserve ±0 integer part; fractional part is always +0 (php-src / VmMath).
            self::$lastIntPart = $num;

            return 0.0;
        }

        $intPart = self::truncTowardInfinity($num);
        self::$lastIntPart = $intPart;

        return $num - $intPart;
    }

    public static function intPart(): float
    {
        return self::$lastIntPart;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastIntPart = 0.0;
    }

    /**
     * Floor for positives / ceil for negatives (php-src modf / shared math SSOT).
     * Inlined Floor/Ceil trunc peels — do not call those helpers
     * (NestedJIT cross-class stubs zero under thin AOT — Hypot #27838 class).
     *
     * For both signs, trunc toward zero matches floor(positive) and ceil(negative).
     */
    private static function truncTowardInfinity(float $num): float
    {
        $asInt = (int) $num;
        $asFloat = (float) $asInt;
        if ($num >= 0.0) {
            // floor: trunc toward zero is already toward −∞ for positives.
            return $asFloat;
        }
        // ceil for negatives = trunc toward zero; preserve -0 for (-1,0).
        if (0.0 === $asFloat) {
            if ($num < 0.0) {
                return -0.0;
            }

            return $asFloat;
        }

        return $asFloat;
    }
}
