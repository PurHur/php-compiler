<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * modf() for compiled JIT/AOT modules (#15200, php-in-PHP).
 *
 * SSOT: {@see VmMath::modf()}
 * php-src: ext/standard/math.c — PHP_FUNCTION(modf)
 */
final class ModfJitHelper
{
    private static float $lastIntPart = 0.0;

    public static function compute(float $num): float
    {
        $intPart = 0.0;
        $frac = VmMath::modf($num, $intPart);
        self::$lastIntPart = $intPart;

        return $frac;
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
}
