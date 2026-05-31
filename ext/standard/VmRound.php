<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * round() precision and mode (php-src ext/standard/math.c — _php_math_round).
 */
final class VmRound
{
    /** @var list<float> */
    private const POWERS_OF_10 = [
        1e0, 1e1, 1e2, 1e3, 1e4, 1e5, 1e6, 1e7, 1e8, 1e9, 1e10, 1e11,
        1e12, 1e13, 1e14, 1e15, 1e16, 1e17, 1e18, 1e19, 1e20, 1e21, 1e22,
    ];

    public static function apply(
        Variable $returnVar,
        Variable $numVar,
        int $precision = 0,
        int $mode = StdlibConstants::PHP_ROUND_HALF_UP
    ): void {
        self::validateMode($mode);
        $returnVar->float(self::mathRound(VmMath::toFloat($numVar), $precision, $mode));
    }

    public static function validateMode(int $mode): void
    {
        if (
            StdlibConstants::PHP_ROUND_HALF_UP !== $mode
            && StdlibConstants::PHP_ROUND_HALF_DOWN !== $mode
            && StdlibConstants::PHP_ROUND_HALF_EVEN !== $mode
            && StdlibConstants::PHP_ROUND_HALF_ODD !== $mode
        ) {
            throw new \ValueError('round(): Argument #3 ($mode) must be a valid rounding mode');
        }
    }

    public static function mathRound(float $value, int $places, int $mode): float
    {
        if (!\is_finite($value) || 0.0 === $value) {
            return $value;
        }

        if ($places < \PHP_INT_MIN + 1) {
            $places = \PHP_INT_MIN + 1;
        }

        $exponent = self::intPow10(abs($places));

        if ($value >= 0.0) {
            $tmpValue = \floor($places > 0 ? $value * $exponent : $value / $exponent);
            $tmpValue2 = $tmpValue + 1.0;
        } else {
            $tmpValue = \ceil($places > 0 ? $value * $exponent : $value / $exponent);
            $tmpValue2 = $tmpValue - 1.0;
        }

        if (($places > 0 ? $tmpValue2 / $exponent : $tmpValue2 * $exponent) === $value) {
            $tmpValue = $tmpValue2;
        }

        if (\abs($tmpValue) >= 1e16) {
            return $value;
        }

        $tmpValue = self::roundHelper($tmpValue, $value, $exponent, $places, $mode);

        if (abs($places) < 23) {
            if ($places > 0) {
                return $tmpValue / $exponent;
            }

            return $tmpValue * $exponent;
        }

        $buf = \sprintf('%15fe%d', $tmpValue, -$places);
        $converted = (float) $buf;
        if (!\is_finite($converted) || \is_nan($converted)) {
            return $value;
        }

        return $converted;
    }

    private static function intPow10(int $power): float
    {
        if ($power < 0 || $power > 22) {
            return 10 ** $power;
        }

        return self::POWERS_OF_10[$power];
    }

    private static function roundHelper(
        float $integral,
        float $value,
        float $exponent,
        int $places,
        int $mode
    ): float {
        $valueAbs = \abs($value);
        $edgeCase = self::getBasicEdgeCase($integral, $exponent, $places);

        switch ($mode) {
            case StdlibConstants::PHP_ROUND_HALF_UP:
                if ($valueAbs >= $edgeCase) {
                    return $integral + self::copySign(1.0, $integral);
                }

                return $integral;

            case StdlibConstants::PHP_ROUND_HALF_DOWN:
                if ($valueAbs > $edgeCase) {
                    return $integral + self::copySign(1.0, $integral);
                }

                return $integral;

            case StdlibConstants::PHP_ROUND_HALF_EVEN:
                if ($valueAbs > $edgeCase) {
                    return $integral + self::copySign(1.0, $integral);
                }
                if ($valueAbs === $edgeCase) {
                    $even = 0.0 === \fmod($integral, 2.0);
                    if (!$even) {
                        return $integral + self::copySign(1.0, $integral);
                    }
                }

                return $integral;

            case StdlibConstants::PHP_ROUND_HALF_ODD:
                if ($valueAbs > $edgeCase) {
                    return $integral + self::copySign(1.0, $integral);
                }
                if ($valueAbs === $edgeCase) {
                    $even = 0.0 === \fmod($integral, 2.0);
                    if ($even) {
                        return $integral + self::copySign(1.0, $integral);
                    }
                }

                return $integral;

            default:
                throw new \ValueError('round(): Argument #3 ($mode) must be a valid rounding mode');
        }
    }

    private static function getBasicEdgeCase(float $integral, float $exponent, int $places): float
    {
        if ($places > 0) {
            return \abs(($integral + self::copySign(0.5, $integral)) / $exponent);
        }

        return \abs(($integral + self::copySign(0.5, $integral)) * $exponent);
    }

    private static function copySign(float $magnitude, float $signSource): float
    {
        return $signSource >= 0.0 ? $magnitude : -$magnitude;
    }
}
