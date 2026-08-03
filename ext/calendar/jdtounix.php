<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** jdtounix() — Julian day to Unix timestamp (php-src ext/calendar/cal_unix.c; #6759 / #27387). */
final class jdtounix extends Internal
{
    public function __construct()
    {
        parent::__construct('jdtounix');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'jdtounix() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $julianDay = CalendarArgs::requireInt($frame, 'jdtounix', 1, 'julian_day');
        $frame->returnVar->int(VmCalendar::jdtounix($julianDay));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdtounix() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitJdtounix::invoke($context, ...$args);
    }
}
