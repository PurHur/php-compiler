<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** juliantojd() — Julian calendar to Julian day number (php-src ext/calendar/calendar.c; #11875). */
final class juliantojd extends Internal
{
    public function __construct()
    {
        parent::__construct('juliantojd');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'juliantojd() expects exactly 3 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $month = CalendarArgs::requireInt($frame, 'juliantojd', 1, 'month');
        $day = CalendarArgs::requireInt($frame, 'juliantojd', 2, 'day');
        $year = CalendarArgs::requireInt($frame, 'juliantojd', 3, 'year');
        $frame->returnVar->int(VmJewishFrenchCalendar::juliantojd($month, $day, $year));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('juliantojd() is not implemented for JIT in this compiler build (issue #11875)');
    }
}
