<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * jdmonthname() — month name for a Julian day (php-src ext/calendar/calendar.c; #7252 / #27360).
 */
final class jdmonthname extends Internal
{
    public function __construct()
    {
        parent::__construct('jdmonthname');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'jdmonthname() expects exactly 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $jd = CalendarArgs::requireInt($frame, 'jdmonthname', 1, 'julian_day');
        $mode = CalendarArgs::requireInt($frame, 'jdmonthname', 2, 'mode');
        $frame->returnVar->string(VmCalendar::jdMonthName($jd, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdmonthname() expects exactly 2 arguments, '.\count($args).' given'
            );
        }

        return JitJdmonthname::invoke($context, ...$args);
    }
}
