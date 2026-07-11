<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\VM\ObjectEntry;

/**
 * γ-section float sampling (php-src ext/random/gammasection.c; Goualard 2022).
 *
 * Used by Random\Randomizer::getFloat() (#17292).
 */
final class GammaSection
{
    private const RANGE_ATTEMPTS = 50;

    public static function closedOpen(ObjectEntry $engineObject, float $min, float $max): float
    {
        $g = self::gammaMax($min, $max);
        $hi = self::ceilInt($min, $max, $g);
        if ($max <= $min || $hi < 1) {
            return \NAN;
        }

        $k = 1 + RandomEngineStorage::range64($engineObject, $hi - 1);

        if (\abs($min) <= \abs($max)) {
            if ($k === $hi) {
                return $min;
            }
            [$kHi, $kLo] = self::splitInt64($k);

            return 4.0 * ($max * 0.25 - $kHi * $g) - $kLo * $g;
        }
        [$kHi, $kLo] = self::splitInt64($k - 1);

        return 4.0 * ($min * 0.25 + $kHi * $g) + $kLo * $g;
    }

    public static function closedClosed(ObjectEntry $engineObject, float $min, float $max): float
    {
        $g = self::gammaMax($min, $max);
        $hi = self::ceilInt($min, $max, $g);
        if ($max < $min) {
            return \NAN;
        }

        $k = RandomEngineStorage::range64($engineObject, $hi);

        if (\abs($min) <= \abs($max)) {
            if ($k === $hi) {
                return $min;
            }
            [$kHi, $kLo] = self::splitInt64($k);

            return 4.0 * ($max * 0.25 - $kHi * $g) - $kLo * $g;
        }
        if ($k === $hi) {
            return $max;
        }
        [$kHi, $kLo] = self::splitInt64($k);

        return 4.0 * ($min * 0.25 + $kHi * $g) + $kLo * $g;
    }

    public static function openClosed(ObjectEntry $engineObject, float $min, float $max): float
    {
        $g = self::gammaMax($min, $max);
        $hi = self::ceilInt($min, $max, $g);
        if ($max <= $min || $hi < 1) {
            return \NAN;
        }

        $k = RandomEngineStorage::range64($engineObject, $hi - 1);

        if (\abs($min) <= \abs($max)) {
            [$kHi, $kLo] = self::splitInt64($k);

            return 4.0 * ($max * 0.25 - $kHi * $g) - $kLo * $g;
        }
        if ($k === ($hi - 1)) {
            return $max;
        }
        [$kHi, $kLo] = self::splitInt64($k + 1);

        return 4.0 * ($min * 0.25 + $kHi * $g) + $kLo * $g;
    }

    public static function openOpen(ObjectEntry $engineObject, float $min, float $max): float
    {
        $g = self::gammaMax($min, $max);
        $hi = self::ceilInt($min, $max, $g);
        if ($max <= $min || $hi < 2) {
            return \NAN;
        }

        $k = 1 + RandomEngineStorage::range64($engineObject, $hi - 2);

        if (\abs($min) <= \abs($max)) {
            [$kHi, $kLo] = self::splitInt64($k);

            return 4.0 * ($max * 0.25 - $kHi * $g) - $kLo * $g;
        }
        [$kHi, $kLo] = self::splitInt64($k);

        return 4.0 * ($min * 0.25 + $kHi * $g) + $kLo * $g;
    }

    private static function gammaLow(float $x): float
    {
        return $x - VmMath::nextafter($x, -\INF);
    }

    private static function gammaHigh(float $x): float
    {
        return VmMath::nextafter($x, \INF) - $x;
    }

    private static function gammaMax(float $x, float $y): float
    {
        return (\abs($x) > \abs($y)) ? self::gammaHigh($x) : self::gammaLow($y);
    }

    /** @return array{0: float, 1: float} */
    private static function splitInt64(int $v): array
    {
        return [$v >> 2, $v & 0x3];
    }

    private static function ceilInt(float $a, float $b, float $g): int
    {
        $s = $b / $g - $a / $g;
        if (\abs($a) <= \abs($b)) {
            $e = -$a / $g - ($s - $b / $g);
        } else {
            $e = $b / $g - ($s + $a / $g);
        }

        $si = (float) \ceil($s);

        return ($s !== $si) ? (int) $si : (int) $si + ($e > 0 ? 1 : 0);
    }
}
