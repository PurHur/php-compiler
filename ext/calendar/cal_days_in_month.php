<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * cal_days_in_month() — Gregorian/Julian month lengths (php-src ext/calendar/calendar.c; #7223).
 *
 * Z_PARAM_LONG args — null soft-null DEP+coerce via CalendarArgs (#24967).
 * Month ≤ 0 falls through to VmCalendar Invalid date (php-src SDN helpers).
 */
final class cal_days_in_month extends Internal
{
    public function __construct()
    {
        parent::__construct('cal_days_in_month');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'cal_days_in_month() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $calendar = CalendarArgs::requireInt($frame, 'cal_days_in_month', 1, 'calendar');
        $month = CalendarArgs::requireInt($frame, 'cal_days_in_month', 2, 'month');
        $year = CalendarArgs::requireInt($frame, 'cal_days_in_month', 3, 'year');
        self::assertValidCalendarId($calendar);
        self::assertMonthYear($month, $year);
        $frame->returnVar->int(VmCalendar::calDaysInMonth($calendar, $month, $year));
    }

    private static function assertValidCalendarId(int $calendar): void
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_days_in_month(): Argument #1 ($calendar) must be a valid calendar ID'
            );
        }
    }

    private static function assertMonthYear(int $month, int $year): void
    {
        // php-src calendar.c does not ValueError on month<=0 — SDN helpers throw Invalid date
        // (needed for null soft-null → 0; #24967 / #24864 cal_to_jd).
        if ($month > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_days_in_month(): Argument #2 ($month) must be between 1 and %d', \PHP_INT_MAX - 1)
            );
        }
        if ($year > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_days_in_month(): Argument #3 ($year) must be less than %d', \PHP_INT_MAX - 1)
            );
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(
                'cal_days_in_month() expects exactly 3 arguments, '.\count($args).' given'
            );
        }

        return JitCalDaysInMonth::invoke($context, ...$args);
    }
}
