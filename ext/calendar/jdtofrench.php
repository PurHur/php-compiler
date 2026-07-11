<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** jdtofrench() — Julian day to French republican date string (php-src ext/calendar/calendar.c; #11875). */
final class jdtofrench extends Internal
{
    public function __construct()
    {
        parent::__construct('jdtofrench');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'jdtofrench() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $julday = CalendarArgs::requireInt($frame, 'jdtofrench', 1, 'julday');
        $frame->returnVar->string(VmJewishFrenchCalendar::jdtofrench($julday));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('jdtofrench() is not implemented for JIT in this compiler build (issue #11875)');
    }
}
