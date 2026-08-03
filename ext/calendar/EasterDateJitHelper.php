<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * easter_date() for compiled JIT/AOT modules (#27356, php-in-PHP).
 *
 * NestedJIT-safe: easter_days math + UTC midnight via jdtounix/gregorianToJd
 * (no VmDate::mktime). Matches php-src easter.c local midnight when TZ=UTC.
 * SSOT on the VM path: {@see VmCalendar::easterDate()}
 * php-src: ext/calendar/easter.c — PHP_FUNCTION(easter_date)
 */
final class EasterDateJitHelper
{
    public static function easterDateArgv(int $year, int $mode): int
    {
        self::assertEasterYear($year);
        $easter = VmCalendar::easterDays($year, $mode);
        if ($easter < 11) {
            $month = 3;
            $day = $easter + 21;
        } else {
            $month = 4;
            $day = $easter - 10;
        }

        return VmCalendar::jdtounix(VmCalendar::gregorianToJd($month, $day, $year));
    }

    /** Omitted/null $year — local calendar year (php-src easter.c). */
    public static function easterDateNowArgv(int $mode): int
    {
        return self::easterDateArgv(self::currentYear(), $mode);
    }

    public static function currentYear(): int
    {
        return (int) \date('Y');
    }

    private static function assertEasterYear(int $year): void
    {
        $maxYear = intdiv(\PHP_INT_MAX, 5) * 4;
        if ($year <= 0 || $year > $maxYear) {
            throw new \ValueError(
                \sprintf('easter_date(): Argument #1 ($year) must be between 1 and %d', $maxYear)
            );
        }
        if (\PHP_INT_SIZE >= 8) {
            if ($year < 1970) {
                throw new \ValueError('easter_date(): Argument #1 ($year) must be a year after 1970 (inclusive)');
            }
            if ($year > 2000000000) {
                throw new \ValueError(
                    'easter_date(): Argument #1 ($year) must be a year before 2.000.000.000 (inclusive)'
                );
            }
        } elseif ($year < 1970 || $year > 2037) {
            throw new \ValueError('easter_date(): Argument #1 ($year) must be between 1970 and 2037 (inclusive)');
        }
    }
}
