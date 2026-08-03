<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** jdtojewish() — Julian day to Jewish calendar string (php-src ext/calendar/calendar.c; #11875 / #27368). */
final class jdtojewish extends Internal
{
    public function __construct()
    {
        parent::__construct('jdtojewish');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'jdtojewish() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        // php-src calendar.stub.php — int $julian_day, bool $hebrew = false, int $flags = 0 (#24362)
        $julday = CalendarArgs::requireInt($frame, 'jdtojewish', 1, 'julian_day');
        $hebrew = false;
        $flags = 0;
        if ($argc >= 2) {
            $hebrew = VmMath::parseBoolBuiltinArgForFrame($frame, 1, 'jdtojewish', 2, 'hebrew');
        }
        if ($argc >= 3) {
            $flags = CalendarArgs::requireInt($frame, 'jdtojewish', 3, 'flags');
        }
        // Hebrew formatting flags are accepted for arity/named-arg parity; numeric form ignores them today.
        $mode = $hebrew ? $flags : 0;
        $frame->returnVar->string(VmJewishFrenchCalendar::jdtojewish($julday, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'jdtojewish() expects at most 3 arguments, '.$argc.' given'
            );
        }

        return JitJdtojewish::invoke($context, ...$args);
    }
}
