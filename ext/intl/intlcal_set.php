<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * intlcal_set() — procedural IntlCalendar alias
 * (php-src calendar_methods.c / calendar.stub.php; #20836).
 */
final class intlcal_set extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $userArgc = max(0, $argc - 1);
        if ($userArgc < 2 || $userArgc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_set() expects between 3 and 7 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_set',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $a0 = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlcal_set', 1, 'year');
        $a1 = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'intlcal_set', 2, 'month');
        if (2 === $userArgc) {
            $ok = VmIntlCalendar::setField($cal, $a0, $a1);
        } else {
            $day = $userArgc >= 3
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'intlcal_set', 3, 'dayOfMonth')
                : null;
            $hour = $userArgc >= 4
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[4], 'intlcal_set', 4, 'hour')
                : null;
            $minute = $userArgc >= 5
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[5], 'intlcal_set', 5, 'minute')
                : null;
            $second = $userArgc >= 6
                ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[6], 'intlcal_set', 6, 'second')
                : null;
            $ok = VmIntlCalendar::setDate($cal, $a0, $a1, $day, $hour, $minute, $second);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_set() is not implemented for JIT in this compiler build (issue #20836)');
    }
}
