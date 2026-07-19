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
 * intlcal_set_repeated_wall_time_option() — procedural IntlCalendar::setRepeatedWallTimeOption
 * (php-src calendar_methods.c / calendar.stub.php; #20895).
 */
final class intlcal_set_repeated_wall_time_option extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_set_repeated_wall_time_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_set_repeated_wall_time_option() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_set_repeated_wall_time_option',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $cal = $receiver->toObject();
        $option = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlcal_set_repeated_wall_time_option', 1, 'option');
        $ok = VmIntlCalendar::setRepeatedWallTimeOption($cal, $option);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_set_repeated_wall_time_option() is not implemented for JIT in this compiler build (issue #20895)');
    }
}