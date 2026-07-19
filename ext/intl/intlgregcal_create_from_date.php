<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** intlgregcal_create_from_date() — procedural IntlGregorianCalendar::createFromDate (#20906). */
final class intlgregcal_create_from_date extends Internal
{
    public function __construct()
    {
        parent::__construct('intlgregcal_create_from_date');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'intlgregcal_create_from_date() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[0], 'intlgregcal_create_from_date', 0, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlgregcal_create_from_date', 1, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'intlgregcal_create_from_date', 2, 'dayOfMonth');
        VmIntlCalendar::assertInt32Field($year, 1, 'intlgregcal_create_from_date');
        VmIntlCalendar::assertInt32Field($month, 2, 'intlgregcal_create_from_date');
        VmIntlCalendar::assertInt32Field($day, 3, 'intlgregcal_create_from_date');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlCalendar::createGregorianFromDate(
            $frame->vmContext,
            $year,
            $month,
            $day
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlgregcal_create_from_date() is not implemented for JIT in this compiler build (issue #20906)');
    }
}
