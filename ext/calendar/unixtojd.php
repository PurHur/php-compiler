<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** unixtojd() — Unix timestamp to Julian day (php-src ext/calendar/cal_unix.c; #6759 / #27367). */
final class unixtojd extends Internal
{
    public function __construct()
    {
        parent::__construct('unixtojd');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'unixtojd() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = CalendarArgs::optionalInt($frame, 'unixtojd', 1, 'timestamp');
        $frame->returnVar->int(VmCalendar::unixtojd($timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'unixtojd() expects at most 1 argument, '.$argc.' given'
            );
        }

        return JitUnixtojd::invoke($context, ...$args);
    }
}
