<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** intlgregcal_is_leap_year() — procedural IntlGregorianCalendar::isLeapYear (#20906). */
final class intlgregcal_is_leap_year extends Internal
{
    public function __construct()
    {
        parent::__construct('intlgregcal_is_leap_year');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlgregcal_is_leap_year() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlCalendar::isGregorianCalendarObject($receiver->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #1 ($calendar) must be of type IntlGregorianCalendar, %s given',
                'intlgregcal_is_leap_year',
                Variable::TYPE_OBJECT === $receiver->type
                    ? $receiver->toObject()->class->name
                    : ReflectionSupport::valueTypeLabelPublic($receiver)
            ));
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlgregcal_is_leap_year', 1, 'year');
        VmIntlCalendar::assertInt32Field($year, 2, 'intlgregcal_is_leap_year');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlCalendar::isLeapYear($receiver->toObject(), $year));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlgregcal_is_leap_year() is not implemented for JIT in this compiler build (issue #20906)');
    }
}
