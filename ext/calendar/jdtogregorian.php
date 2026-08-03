<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** jdtogregorian() — Julian day to Gregorian date string (php-src ext/calendar/calendar.c; #6759). */
final class jdtogregorian extends Internal
{
    public function __construct()
    {
        parent::__construct('jdtogregorian');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'jdtogregorian() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src calendar.stub.php — int $julian_day (#24864 / #24362)
        $julday = CalendarArgs::requireInt($frame, 'jdtogregorian', 1, 'julian_day');
        $frame->returnVar->string(VmCalendar::jdtogregorian($julday));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdtogregorian() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitJdtogregorian::invoke($context, ...$args);
    }
}
