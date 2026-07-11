<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * frexp() for compiled JIT/AOT modules (#15201, php-in-PHP).
 *
 * SSOT: {@see VmMath::frexp()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(frexp)
 */
final class FrexpJitHelper
{
    private static int $lastExp = 0;

    public static function compute(float $num): float
    {
        $exp = 0;
        $frac = VmMath::frexp($num, $exp);
        self::$lastExp = $exp;

        return $frac;
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
