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
 * intlcal_set_time_zone() — procedural IntlCalendar::setTimeZone
 * (php-src calendar_methods.c / calendar.stub.php; #20897).
 */
final class intlcal_set_time_zone extends Internal
{
    public function __construct()
    {
        parent::__construct('intlcal_set_time_zone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlcal_set_time_zone() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlCalendar, %s given',
                'intlcal_set_time_zone',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $tz = VmIntlTimeZone::resolveTimezoneOperand(
            $frame->calledArgs[1],
            $frame->vmContext,
            'intlcal_set_time_zone',
            1
        );
        $ok = VmIntlCalendar::setTimeZoneId($receiver->toObject(), $tz);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlcal_set_time_zone() is not implemented for JIT in this compiler build (issue #20897)');
    }
}
