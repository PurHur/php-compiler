<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** jdtojewish() — Julian day to Jewish calendar string (php-src ext/calendar/calendar.c; #11875). */
final class jdtojewish extends Internal
{
    public function __construct()
    {
        parent::__construct('jdtojewish');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'jdtojewish() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $julday = CalendarArgs::requireInt($frame, 'jdtojewish', 1, 'julday');
        $mode = CalendarArgs::optionalInt($frame, 'jdtojewish', 2, 'mode') ?? 0;
        $frame->returnVar->string(VmJewishFrenchCalendar::jdtojewish($julday, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('jdtojewish() is not implemented for JIT in this compiler build (issue #11875)');
    }
}
