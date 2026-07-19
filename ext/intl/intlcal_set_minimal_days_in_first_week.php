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
 * intlcal_set_minimal_days_in_first_week() — procedural IntlCalendar::setMinimalDaysInFirstWeek
 * (php-src calendar_methods.c / calendar.stub.php; #20896).
 */
final class intlcal_set_minimal_days_in_first_week extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_set_minimal_days_in_first_week');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_set_minimal_days_in_first_week() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_set_minimal_days_in_first_week',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $days = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlcal_set_minimal_days_in_first_week', 1, 'minimalDays');
        $ok = VmIntlCalendar::setMinimalDaysInFirstWeek($cal, $days);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_set_minimal_days_in_first_week() is not implemented for JIT in this compiler build (issue #20896)');
    }
}