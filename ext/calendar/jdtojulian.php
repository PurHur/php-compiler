<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** jdtojulian() — Julian day to Julian calendar date string (php-src ext/calendar/calendar.c; #6759 / #27388). */
final class jdtojulian extends Internal
{
    public function __construct()
    {
        parent::__construct('jdtojulian');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'jdtojulian() expects exactly 1 argument, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src calendar.stub.php — int $julian_day (#24864 / #24362)
        $julday = CalendarArgs::requireInt($frame, 'jdtojulian', 1, 'julian_day');
        $frame->returnVar->string(VmCalendar::jdtojulian($julday));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(
                'jdtojulian() expects exactly 1 argument, '.\count($args).' given'
            );
        }

        return JitJdtojulian::invoke($context, ...$args);
    }
}
