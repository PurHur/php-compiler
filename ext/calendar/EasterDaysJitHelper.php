<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * easter_days() for compiled JIT/AOT modules (#27358, php-in-PHP).
 *
 * SSOT: {@see VmCalendar::easterDays()}
 * php-src: ext/calendar/easter.c — PHP_FUNCTION(easter_days)
 */
final class EasterDaysJitHelper
{
    public static function easterDaysArgv(int $year, int $mode): int
    {
        self::assertEasterYear($year);

        return VmCalendar::easterDays($year, $mode);
    }

    /** Omitted/null $year — local calendar year (php-src easter.c). */
    public static function easterDaysNowArgv(int $mode): int
    {
        return self::easterDaysArgv(self::currentYear(), $mode);
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
                \sprintf('easter_days(): Argument #1 ($year) must be between 1 and %d', $maxYear)
            );
        }
    }
}
