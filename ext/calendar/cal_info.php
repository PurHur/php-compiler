<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * cal_info() — calendar metadata (php-src ext/calendar/calendar.c; #7252 / #27354).
 *
 * Z_PARAM_LONG optional calendar — null soft-null DEP+coerce via CalendarArgs (#24967).
 */
final class cal_info extends Internal
{
    public function __construct()
    {
        parent::__construct('cal_info');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('cal_info() accepts at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->array(VmCalendar::calInfoAll());

            return;
        }
        $cal = CalendarArgs::requireInt($frame, 'cal_info', 1, 'calendar');
        CalendarArgs::assertValidCalendarId($cal, 'cal_info');
        $frame->returnVar->array(VmCalendar::calInfo($cal));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \ArgumentCountError(
                'cal_info() accepts at most 1 argument, '.\count($args).' given'
            );
        }

        return JitCalInfo::invoke($context, ...$args);
    }
}
