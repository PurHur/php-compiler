<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * cal_from_jd() — Julian day to calendar breakdown (php-src ext/calendar/calendar.c; #7252 / #27359).
 */
final class cal_from_jd extends Internal
{
    public function __construct()
    {
        parent::__construct('cal_from_jd');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'cal_from_jd() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src calendar.stub.php — int $julian_day (#24864 / #24362)
        $jd = CalendarArgs::requireInt($frame, 'cal_from_jd', 1, 'julian_day');
        $cal = CalendarArgs::requireInt($frame, 'cal_from_jd', 2, 'calendar');
        CalendarArgs::assertValidCalendarId($cal, 'cal_from_jd', 2);
        $frame->returnVar->array(VmCalendar::calFromJd($jd, $cal));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'cal_from_jd() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitCalFromJd::invoke($context, ...$args);
    }
}
