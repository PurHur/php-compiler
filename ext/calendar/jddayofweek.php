<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * jddayofweek() — day-of-week for a Julian day (php-src ext/calendar/dow.c; #7252).
 */
final class jddayofweek extends Internal
{
    public function __construct()
    {
        parent::__construct('jddayofweek');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'jddayofweek() expects at least 1 argument, '.\max(0, $argc - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $jd = CalendarArgs::requireInt($frame, 'jddayofweek', 1, 'julian_day');
        $mode = 1 === $argc
            ? CalendarConstants::CAL_DOW_DAYNO
            : CalendarArgs::requireInt($frame, 'jddayofweek', 2, 'mode');
        $result = VmCalendar::jdDayOfWeek($jd, $mode);
        if (\is_int($result)) {
            $frame->returnVar->int($result);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('jddayofweek() is not implemented for JIT in this compiler build (issue #7252)');
    }
}
