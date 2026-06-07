<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * Pure PHP calendar math ported from php-src ext/calendar (sdncal / easter.c; #7223).
 *
 * php-src: ext/calendar/gregor.c, julian.c, calendar.c, easter.c
 */
final class VmCalendar
{
    private const GREGOR_SDN_OFFSET = 32045;
    private const JULIAN_SDN_OFFSET = 32083;
    private const DAYS_PER_5_MONTHS = 153;
    private const DAYS_PER_4_YEARS = 1461;
    private const DAYS_PER_400_YEARS = 146097;

    public static function calDaysInMonth(int $calendar, int $month, int $year): int
    {
        $sdnStart = self::calendarToSdn($calendar, $year, $month, 1);
        if (0 === $sdnStart) {
            throw new \ValueError('Invalid date');
        }

        $nextMonth = 1 + $month;
        $sdnNext = self::calendarToSdn($calendar, $year, $nextMonth, 1);
        if (0 === $sdnNext) {
            if (-1 === $year) {
                $sdnNext = self::calendarToSdn($calendar, 1, 1, 1);
            } else {
                $sdnNext = self::calendarToSdn($calendar, $year + 1, 1, 1);
                if (CalendarConstants::CAL_FRENCH === $calendar && 0 === $sdnNext) {
                    $sdnNext = 2380953;
                }
            }
        }
        if (0 === $sdnNext) {
            throw new \ValueError('Invalid date');
        }

        return (int) ($sdnNext - $sdnStart);
    }

    public static function gregorianToJd(int $month, int $day, int $year): int
    {
        return self::gregorianToSdn($year, $month, $day);
    }

    public static function easterDate(int $year): int
    {
        $easter = self::easterDays($year, CalendarConstants::CAL_EASTER_DEFAULT);
        if ($easter < 11) {
            $month = 3;
            $day = $easter + 21;
        } else {
            $month = 4;
            $day = $easter - 10;
        }

        return self::localMidnightTimestamp($year, $month, $day);
    }

    public static function easterDays(int $year, int $method = CalendarConstants::CAL_EASTER_DEFAULT): int
    {
        $golden = ($year % 19) + 1;

        if (($year <= 1582 && CalendarConstants::CAL_EASTER_ALWAYS_GREGORIAN !== $method)
            || ($year >= 1583 && $year <= 1752
                && CalendarConstants::CAL_EASTER_ROMAN !== $method
                && CalendarConstants::CAL_EASTER_ALWAYS_GREGORIAN !== $method)
            || CalendarConstants::CAL_EASTER_ALWAYS_JULIAN === $method) {
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

    private static function calendarToSdn(int $calendar, int $year, int $month, int $day): int
    {
        return match ($calendar) {
            CalendarConstants::CAL_GREGORIAN => self::gregorianToSdn($year, $month, $day),
            CalendarConstants::CAL_JULIAN => self::julianToSdn($year, $month, $day),
            default => throw new \LogicException(
                'Calendar ID '.$calendar.' is not implemented in this compiler build (issue #3742)'
            ),
        };
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

    private static function julianToSdn(int $inputYear, int $inputMonth, int $inputDay): int
    {
        if (0 === $inputYear || $inputYear < -4713
            || $inputMonth <= 0 || $inputMonth > 12
            || $inputDay <= 0 || $inputDay > 31) {
            return 0;
        }
        if (-4713 === $inputYear && 1 === $inputMonth && 1 === $inputDay) {
            return 0;
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

        return intdiv($year * self::DAYS_PER_4_YEARS, 4)
            + intdiv($month * self::DAYS_PER_5_MONTHS + 2, 5)
            + $inputDay
            - self::JULIAN_SDN_OFFSET;
    }

    private static function localMidnightTimestamp(int $year, int $month, int $day): int
    {
        if (\function_exists('mktime')) {
            $ts = \mktime(0, 0, 0, $month, $day, $year);
            if (false !== $ts) {
                return (int) $ts;
            }
        }

        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->mktime(0, 0, 0, $month, $day, $year);
        }

        throw new \LogicException('easter_date() requires mktime support in this compiler build');
    }

    private static function ffi(): ?\FFI
    {
        static $ffi = null;
        static $probed = false;
        if ($probed) {
            return $ffi;
        }
        $probed = true;
        if (!\extension_loaded('ffi')) {
            return null;
        }
        $cdef = <<<'CDEF'
typedef long time_t;
time_t mktime(int hour, int min, int sec, int mon, int day, int year);
CDEF;
        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                $ffi = \FFI::cdef($cdef, $lib);

                return $ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
