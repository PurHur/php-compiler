<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gregoriantojd() — Gregorian serial day number (php-src ext/calendar/calendar.c; #7223 / #27386).
 *
 * Z_PARAM_LONG args — null soft-null DEP+coerce via CalendarArgs (#24967).
 */
final class gregoriantojd extends Internal
{
    public function __construct()
    {
        parent::__construct('gregoriantojd');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'gregoriantojd() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $month = CalendarArgs::requireInt($frame, 'gregoriantojd', 1, 'month');
        $day = CalendarArgs::requireInt($frame, 'gregoriantojd', 2, 'day');
        $year = CalendarArgs::requireInt($frame, 'gregoriantojd', 3, 'year');
        $frame->returnVar->int(VmCalendar::gregorianToJd($month, $day, $year));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(
                'gregoriantojd() expects exactly 3 arguments, '.\count($args).' given'
            );
        }

        return JitGregoriantojd::invoke($context, ...$args);
    }
}
