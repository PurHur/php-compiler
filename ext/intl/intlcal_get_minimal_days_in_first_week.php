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
 * intlcal_get_minimal_days_in_first_week() — procedural IntlCalendar::getMinimalDaysInFirstWeek
 * (php-src calendar_methods.c / calendar.stub.php; #20896).
 */
final class intlcal_get_minimal_days_in_first_week extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_get_minimal_days_in_first_week');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_get_minimal_days_in_first_week() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_get_minimal_days_in_first_week',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $result = VmIntlCalendar::getMinimalDaysInFirstWeek($cal);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_get_minimal_days_in_first_week() is not implemented for JIT in this compiler build (issue #20896)');
    }
}