<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * easter_date() for compiled JIT/AOT modules (#27356, php-in-PHP).
 *
 * NestedJIT leaf: inlined easter_days + gregorian SDN + jdtounix. No throws —
 * ValueError NestedJIT fails module verify ("Referring to a basic block in another
 * function" / throw_uncaught) and blocks helper-runtime refresh #24302. VM wrappers
 * still validate years before calling.
 * php-src: ext/calendar/easter.c — PHP_FUNCTION(easter_date)
 */
final class EasterDateJitHelper
{
    private const UNIX_EPOCH_JD = 2440588;

    private const SECS_PER_DAY = 86400;

    private const GREGOR_SDN_OFFSET = 32045;

    private const DAYS_PER_400_YEARS = 146097;

    private const DAYS_PER_4_YEARS = 1461;

    private const DAYS_PER_5_MONTHS = 153;

    private const EASTER_ROMAN = 1;

    private const EASTER_ALWAYS_GREGORIAN = 2;

    private const EASTER_ALWAYS_JULIAN = 3;

    public static function easterDateArgv(int $year, int $mode): int
    {
        if ($year < 1970) {
            return 0;
        }
        $easter = self::easterDays($year, $mode);
        if ($easter < 11) {
            $month = 3;
            $day = $easter + 21;
        } else {
            $month = 4;
            $day = $easter - 10;
        }

        return self::jdtounix(self::gregorianToSdn($year, $month, $day));
    }

    public static function easterDateNowArgv(int $mode): int
    {
        return self::easterDateArgv(self::currentYear(), $mode);
    }

    public static function currentYear(): int
    {
        $days = intdiv(\time(), 86400);
        $z = $days + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);

        return $yoe + $era * 400;
    }

    private static function easterDays(int $year, int $method): int
    {
        $golden = ($year % 19) + 1;

        if (($year <= 1582 && self::EASTER_ALWAYS_GREGORIAN !== $method)
            || ($year >= 1583 && $year <= 1752
                && self::EASTER_ROMAN !== $method
                && self::EASTER_ALWAYS_GREGORIAN !== $method)
            || self::EASTER_ALWAYS_JULIAN === $method) {
            $dom = ($year + intdiv($year, 4) + 5) % 7;
            if ($dom < 0) {
                $dom += 7;
            }
            $pfm = (3 - (11 * $golden) - 7) % 30;
            if ($pfm < 0) {
                $pfm += 30;
            }
        } else {
            $dom = ($year + intdiv($year, 4) - intdiv($year, 100) + intdiv($year, 400)) % 7;
            if ($dom < 0) {
                $dom += 7;
            }
            $solar = intdiv($year - 1600, 100) - intdiv($year - 1600, 400);
            $lunar = intdiv(intdiv($year - 1400, 100) * 8, 25);
            $pfm = (3 - (11 * $golden) + $solar - $lunar) % 30;
            if ($pfm < 0) {
                $pfm += 30;
            }
        }

        if (29 === $pfm || (28 === $pfm && $golden > 11)) {
            --$pfm;
        }

        $tmp = (4 - $pfm - $dom) % 7;
        if ($tmp < 0) {
            $tmp += 7;
        }

        return (int) ($pfm + $tmp + 1);
    }

    private static function gregorianToSdn(int $inputYear, int $inputMonth, int $inputDay): int
    {
        if (0 === $inputYear || $inputYear < -4714
            || $inputMonth <= 0 || $inputMonth > 12
            || $inputDay <= 0 || $inputDay > 31) {
            return 0;
        }
        if (-4714 === $inputYear) {
            if ($inputMonth < 11 || (11 === $inputMonth && $inputDay < 25)) {
                return 0;
            }
        }

        if ($inputYear < 0) {
            $year = $inputYear + 4801;
        } else {
            $year = $inputYear + 4800;
        }

        if ($inputMonth > 2) {
            $month = $inputMonth - 3;
        } else {
            $month = $inputMonth + 9;
            --$year;
        }

        return intdiv(intdiv($year, 100) * self::DAYS_PER_400_YEARS, 4)
            + intdiv(($year % 100) * self::DAYS_PER_4_YEARS, 4)
            + intdiv($month * self::DAYS_PER_5_MONTHS + 2, 5)
            + $inputDay
            - self::GREGOR_SDN_OFFSET;
    }

    private static function jdtounix(int $julianDay): int
    {
        $maxJd = self::UNIX_EPOCH_JD + intdiv(\PHP_INT_MAX, self::SECS_PER_DAY);
        if ($julianDay < self::UNIX_EPOCH_JD || $julianDay > $maxJd) {
            return 0;
        }

        return ($julianDay - self::UNIX_EPOCH_JD) * self::SECS_PER_DAY;
    }
}
