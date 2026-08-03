<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** cal_to_jd() — calendar date to Julian day (php-src ext/calendar/calendar.c; #6759). */
final class cal_to_jd extends Internal
{
    public function __construct()
    {
        parent::__construct('cal_to_jd');
    }

    public function execute(Frame $frame): void
    {
        if (4 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'cal_to_jd() expects exactly 4 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $calendar = CalendarArgs::requireInt($frame, 'cal_to_jd', 1, 'calendar');
        $month = CalendarArgs::requireInt($frame, 'cal_to_jd', 2, 'month');
        $day = CalendarArgs::requireInt($frame, 'cal_to_jd', 3, 'day');
        $year = CalendarArgs::requireInt($frame, 'cal_to_jd', 4, 'year');
        self::assertValidCalendarId($calendar);
        self::assertMonthYear($month, $year);
        $frame->returnVar->int(VmCalendar::calToJd($calendar, $month, $day, $year));
    }

    private static function assertValidCalendarId(int $calendar): void
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(
                'cal_to_jd(): Argument #1 ($calendar) must be a valid calendar ID'
            );
        }
    }

    private static function assertMonthYear(int $month, int $year): void
    {
        // php-src calendar.c does not ValueError on month<=0 — SDN helpers return 0
        // (needed for null soft-null → 0 under PROFILE=8.4; #24864).
        if ($month > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_to_jd(): Argument #2 ($month) must be between 1 and %d', \PHP_INT_MAX - 1)
            );
        }
        if ($year > \PHP_INT_MAX - 1) {
            throw new \ValueError(
                \sprintf('cal_to_jd(): Argument #4 ($year) must be less than %d', \PHP_INT_MAX - 1)
            );
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (4 !== \count($args)) {
            throw new \ArgumentCountError(
                'cal_to_jd() expects exactly 4 arguments, '.\count($args).' given'
            );
        }

        return JitCalToJd::invoke($context, ...$args);
    }
}
