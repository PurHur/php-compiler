<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Shared checkdate() calendar core for VM + JIT/AOT (#9242).
 *
 * php-src: ext/standard/datetime.c PHP_FUNCTION(checkdate)
 * Leap-century table avoids %% and ternary — required for nested JIT/AOT compile.
 */
final class VmCheckdate
{
    public static function validate(int $month, int $day, int $year): bool
    {
        if ($year < 1 || $year > 32767) {
            return false;
        }
        if ($month < 1 || $month > 12) {
            return false;
        }
        if ($day < 1) {
            return false;
        }
        $max = self::daysInMonth($year, $month);
        if ($day > $max) {
            return false;
        }

        return true;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if (2 === $month) {
            if (self::isLeapYear($year)) {
                return 29;
            }

            return 28;
        }
        if (4 === $month || 6 === $month || 9 === $month || 11 === $month) {
            return 30;
        }

        return 31;
    }

    private static function isLeapYear(int $year): bool
    {
        if (($year & 3) !== 0) {
            return false;
        }
        if (self::isNonLeapCentury($year)) {
            return false;
        }

        return true;
    }

    private static function isNonLeapCentury(int $year): bool
    {
        if ($year === 100) {
            return true;
        }
        if ($year === 200) {
            return true;
        }
        if ($year === 300) {
            return true;
        }
        if ($year === 500) {
            return true;
        }
        if ($year === 600) {
            return true;
        }
        if ($year === 700) {
            return true;
        }
        if ($year === 900) {
            return true;
        }
        if ($year === 1000) {
            return true;
        }
        if ($year === 1100) {
            return true;
        }
        if ($year === 1300) {
            return true;
        }
        if ($year === 1400) {
            return true;
        }
        if ($year === 1500) {
            return true;
        }
        if ($year === 1700) {
            return true;
        }
        if ($year === 1800) {
            return true;
        }
        if ($year === 1900) {
            return true;
        }
        if ($year === 2100) {
            return true;
        }
        if ($year === 2200) {
            return true;
        }
        if ($year === 2300) {
            return true;
        }
        if ($year === 2500) {
            return true;
        }
        if ($year === 2600) {
            return true;
        }
        if ($year === 2700) {
            return true;
        }
        if ($year === 2900) {
            return true;
        }
        if ($year === 3000) {
            return true;
        }
        if ($year === 3100) {
            return true;
        }
        if ($year === 3300) {
            return true;
        }
        if ($year === 3400) {
            return true;
        }
        if ($year === 3500) {
            return true;
        }
        if ($year === 3700) {
            return true;
        }
        if ($year === 3800) {
            return true;
        }
        if ($year === 3900) {
            return true;
        }
        if ($year === 4100) {
            return true;
        }
        if ($year === 4200) {
            return true;
        }
        if ($year === 4300) {
            return true;
        }
        if ($year === 4500) {
            return true;
        }
        if ($year === 4600) {
            return true;
        }
        if ($year === 4700) {
            return true;
        }
        if ($year === 4900) {
            return true;
        }
        if ($year === 5000) {
            return true;
        }
        if ($year === 5100) {
            return true;
        }
        if ($year === 5300) {
            return true;
        }
        if ($year === 5400) {
            return true;
        }
        if ($year === 5500) {
            return true;
        }
        if ($year === 5700) {
            return true;
        }
        if ($year === 5800) {
            return true;
        }
        if ($year === 5900) {
            return true;
        }
        if ($year === 6100) {
            return true;
        }
        if ($year === 6200) {
            return true;
        }
        if ($year === 6300) {
            return true;
        }
        if ($year === 6500) {
            return true;
        }
        if ($year === 6600) {
            return true;
        }
        if ($year === 6700) {
            return true;
        }
        if ($year === 6900) {
            return true;
        }
        if ($year === 7000) {
            return true;
        }
        if ($year === 7100) {
            return true;
        }
        if ($year === 7300) {
            return true;
        }
        if ($year === 7400) {
            return true;
        }
        if ($year === 7500) {
            return true;
        }
        if ($year === 7700) {
            return true;
        }
        if ($year === 7800) {
            return true;
        }
        if ($year === 7900) {
            return true;
        }
        if ($year === 8100) {
            return true;
        }
        if ($year === 8200) {
            return true;
        }
        if ($year === 8300) {
            return true;
        }
        if ($year === 8500) {
            return true;
        }
        if ($year === 8600) {
            return true;
        }
        if ($year === 8700) {
            return true;
        }
        if ($year === 8900) {
            return true;
        }
        if ($year === 9000) {
            return true;
        }
        if ($year === 9100) {
            return true;
        }
        if ($year === 9300) {
            return true;
        }
        if ($year === 9400) {
            return true;
        }
        if ($year === 9500) {
            return true;
        }
        if ($year === 9700) {
            return true;
        }
        if ($year === 9800) {
            return true;
        }
        if ($year === 9900) {
            return true;
        }
        if ($year === 10100) {
            return true;
        }
        if ($year === 10200) {
            return true;
        }
        if ($year === 10300) {
            return true;
        }
        if ($year === 10500) {
            return true;
        }
        if ($year === 10600) {
            return true;
        }
        if ($year === 10700) {
            return true;
        }
        if ($year === 10900) {
            return true;
        }
        if ($year === 11000) {
            return true;
        }
        if ($year === 11100) {
            return true;
        }
        if ($year === 11300) {
            return true;
        }
        if ($year === 11400) {
            return true;
        }
        if ($year === 11500) {
            return true;
        }
        if ($year === 11700) {
            return true;
        }
        if ($year === 11800) {
            return true;
        }
        if ($year === 11900) {
            return true;
        }
        if ($year === 12100) {
            return true;
        }
        if ($year === 12200) {
            return true;
        }
        if ($year === 12300) {
            return true;
        }
        if ($year === 12500) {
            return true;
        }
        if ($year === 12600) {
            return true;
        }
        if ($year === 12700) {
            return true;
        }
        if ($year === 12900) {
            return true;
        }
        if ($year === 13000) {
            return true;
        }
        if ($year === 13100) {
            return true;
        }
        if ($year === 13300) {
            return true;
        }
        if ($year === 13400) {
            return true;
        }
        if ($year === 13500) {
            return true;
        }
        if ($year === 13700) {
            return true;
        }
        if ($year === 13800) {
            return true;
        }
        if ($year === 13900) {
            return true;
        }
        if ($year === 14100) {
            return true;
        }
        if ($year === 14200) {
            return true;
        }
        if ($year === 14300) {
            return true;
        }
        if ($year === 14500) {
            return true;
        }
        if ($year === 14600) {
            return true;
        }
        if ($year === 14700) {
            return true;
        }
        if ($year === 14900) {
            return true;
        }
        if ($year === 15000) {
            return true;
        }
        if ($year === 15100) {
            return true;
        }
        if ($year === 15300) {
            return true;
        }
        if ($year === 15400) {
            return true;
        }
        if ($year === 15500) {
            return true;
        }
        if ($year === 15700) {
            return true;
        }
        if ($year === 15800) {
            return true;
        }
        if ($year === 15900) {
            return true;
        }
        if ($year === 16100) {
            return true;
        }
        if ($year === 16200) {
            return true;
        }
        if ($year === 16300) {
            return true;
        }
        if ($year === 16500) {
            return true;
        }
        if ($year === 16600) {
            return true;
        }
        if ($year === 16700) {
            return true;
        }
        if ($year === 16900) {
            return true;
        }
        if ($year === 17000) {
            return true;
        }
        if ($year === 17100) {
            return true;
        }
        if ($year === 17300) {
            return true;
        }
        if ($year === 17400) {
            return true;
        }
        if ($year === 17500) {
            return true;
        }
        if ($year === 17700) {
            return true;
        }
        if ($year === 17800) {
            return true;
        }
        if ($year === 17900) {
            return true;
        }
        if ($year === 18100) {
            return true;
        }
        if ($year === 18200) {
            return true;
        }
        if ($year === 18300) {
            return true;
        }
        if ($year === 18500) {
            return true;
        }
        if ($year === 18600) {
            return true;
        }
        if ($year === 18700) {
            return true;
        }
        if ($year === 18900) {
            return true;
        }
        if ($year === 19000) {
            return true;
        }
        if ($year === 19100) {
            return true;
        }
        if ($year === 19300) {
            return true;
        }
        if ($year === 19400) {
            return true;
        }
        if ($year === 19500) {
            return true;
        }
        if ($year === 19700) {
            return true;
        }
        if ($year === 19800) {
            return true;
        }
        if ($year === 19900) {
            return true;
        }
        if ($year === 20100) {
            return true;
        }
        if ($year === 20200) {
            return true;
        }
        if ($year === 20300) {
            return true;
        }
        if ($year === 20500) {
            return true;
        }
        if ($year === 20600) {
            return true;
        }
        if ($year === 20700) {
            return true;
        }
        if ($year === 20900) {
            return true;
        }
        if ($year === 21000) {
            return true;
        }
        if ($year === 21100) {
            return true;
        }
        if ($year === 21300) {
            return true;
        }
        if ($year === 21400) {
            return true;
        }
        if ($year === 21500) {
            return true;
        }
        if ($year === 21700) {
            return true;
        }
        if ($year === 21800) {
            return true;
        }
        if ($year === 21900) {
            return true;
        }
        if ($year === 22100) {
            return true;
        }
        if ($year === 22200) {
            return true;
        }
        if ($year === 22300) {
            return true;
        }
        if ($year === 22500) {
            return true;
        }
        if ($year === 22600) {
            return true;
        }
        if ($year === 22700) {
            return true;
        }
        if ($year === 22900) {
            return true;
        }
        if ($year === 23000) {
            return true;
        }
        if ($year === 23100) {
            return true;
        }
        if ($year === 23300) {
            return true;
        }
        if ($year === 23400) {
            return true;
        }
        if ($year === 23500) {
            return true;
        }
        if ($year === 23700) {
            return true;
        }
        if ($year === 23800) {
            return true;
        }
        if ($year === 23900) {
            return true;
        }
        if ($year === 24100) {
            return true;
        }
        if ($year === 24200) {
            return true;
        }
        if ($year === 24300) {
            return true;
        }
        if ($year === 24500) {
            return true;
        }
        if ($year === 24600) {
            return true;
        }
        if ($year === 24700) {
            return true;
        }
        if ($year === 24900) {
            return true;
        }
        if ($year === 25000) {
            return true;
        }
        if ($year === 25100) {
            return true;
        }
        if ($year === 25300) {
            return true;
        }
        if ($year === 25400) {
            return true;
        }
        if ($year === 25500) {
            return true;
        }
        if ($year === 25700) {
            return true;
        }
        if ($year === 25800) {
            return true;
        }
        if ($year === 25900) {
            return true;
        }
        if ($year === 26100) {
            return true;
        }
        if ($year === 26200) {
            return true;
        }
        if ($year === 26300) {
            return true;
        }
        if ($year === 26500) {
            return true;
        }
        if ($year === 26600) {
            return true;
        }
        if ($year === 26700) {
            return true;
        }
        if ($year === 26900) {
            return true;
        }
        if ($year === 27000) {
            return true;
        }
        if ($year === 27100) {
            return true;
        }
        if ($year === 27300) {
            return true;
        }
        if ($year === 27400) {
            return true;
        }
        if ($year === 27500) {
            return true;
        }
        if ($year === 27700) {
            return true;
        }
        if ($year === 27800) {
            return true;
        }
        if ($year === 27900) {
            return true;
        }
        if ($year === 28100) {
            return true;
        }
        if ($year === 28200) {
            return true;
        }
        if ($year === 28300) {
            return true;
        }
        if ($year === 28500) {
            return true;
        }
        if ($year === 28600) {
            return true;
        }
        if ($year === 28700) {
            return true;
        }
        if ($year === 28900) {
            return true;
        }
        if ($year === 29000) {
            return true;
        }
        if ($year === 29100) {
            return true;
        }
        if ($year === 29300) {
            return true;
        }
        if ($year === 29400) {
            return true;
        }
        if ($year === 29500) {
            return true;
        }
        if ($year === 29700) {
            return true;
        }
        if ($year === 29800) {
            return true;
        }
        if ($year === 29900) {
            return true;
        }
        if ($year === 30100) {
            return true;
        }
        if ($year === 30200) {
            return true;
        }
        if ($year === 30300) {
            return true;
        }
        if ($year === 30500) {
            return true;
        }
        if ($year === 30600) {
            return true;
        }
        if ($year === 30700) {
            return true;
        }
        if ($year === 30900) {
            return true;
        }
        if ($year === 31000) {
            return true;
        }
        if ($year === 31100) {
            return true;
        }
        if ($year === 31300) {
            return true;
        }
        if ($year === 31400) {
            return true;
        }
        if ($year === 31500) {
            return true;
        }
        if ($year === 31700) {
            return true;
        }
        if ($year === 31800) {
            return true;
        }
        if ($year === 31900) {
            return true;
        }
        if ($year === 32100) {
            return true;
        }
        if ($year === 32200) {
            return true;
        }
        if ($year === 32300) {
            return true;
        }
        if ($year === 32500) {
            return true;
        }
        if ($year === 32600) {
            return true;
        }
        if ($year === 32700) {
            return true;
        }
        return false;
    }
}
