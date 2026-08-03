<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** frenchtojd() — French republican calendar to Julian day (php-src ext/calendar/calendar.c; #11875 / #27382). */
final class frenchtojd extends Internal
{
    public function __construct()
    {
        parent::__construct('frenchtojd');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'frenchtojd() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $month = CalendarArgs::requireInt($frame, 'frenchtojd', 1, 'month');
        $day = CalendarArgs::requireInt($frame, 'frenchtojd', 2, 'day');
        $year = CalendarArgs::requireInt($frame, 'frenchtojd', 3, 'year');
        $frame->returnVar->int(VmJewishFrenchCalendar::frenchtojd($month, $day, $year));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \ArgumentCountError(
                'frenchtojd() expects exactly 3 arguments, '.\count($args).' given'
            );
        }

        return JitFrenchtojd::invoke($context, ...$args);
    }
}
