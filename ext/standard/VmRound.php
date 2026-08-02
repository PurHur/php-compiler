<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * round() precision and mode (php-src ext/standard/math.c — _php_math_round).
 *
 * Algorithm SSOT: {@see RoundMath} (NestedJIT/AOT-safe; #26800).
 */
final class VmRound
{
    public static function apply(
        Variable $returnVar,
        Variable $numVar,
        int $precision = 0,
        int $mode = StdlibConstants::PHP_ROUND_HALF_UP
    ): void {
        $returnVar->float(self::mathRound(VmMath::toFloat($numVar), $precision, $mode));
    }

    public static function mathRound(float $value, int $places, int $mode): float
    {
        return RoundMath::mathRound($value, $places, $mode);
    }
}
