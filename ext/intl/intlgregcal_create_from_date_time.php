<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** intlgregcal_create_from_date_time() — procedural IntlGregorianCalendar::createFromDateTime (#20906). */
final class intlgregcal_create_from_date_time extends Internal
{
    public function __construct()
    {
        parent::__construct('intlgregcal_create_from_date_time');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'intlgregcal_create_from_date_time() expects between 5 and 6 arguments, %d given',
                $argc
            ));
        }
        $year = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[0], 'intlgregcal_create_from_date_time', 0, 'year');
        $month = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'intlgregcal_create_from_date_time', 1, 'month');
        $day = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'intlgregcal_create_from_date_time', 2, 'dayOfMonth');
        $hour = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'intlgregcal_create_from_date_time', 3, 'hour');
        $minute = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[4], 'intlgregcal_create_from_date_time', 4, 'minute');
        VmIntlCalendar::assertInt32Field($year, 1, 'intlgregcal_create_from_date_time');
        VmIntlCalendar::assertInt32Field($month, 2, 'intlgregcal_create_from_date_time');
        VmIntlCalendar::assertInt32Field($day, 3, 'intlgregcal_create_from_date_time');
        VmIntlCalendar::assertInt32Field($hour, 4, 'intlgregcal_create_from_date_time');
        VmIntlCalendar::assertInt32Field($minute, 5, 'intlgregcal_create_from_date_time');
        $second = null;
        if (6 === $argc) {
            $secVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $secVar->type) {
                $second = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[5], 'intlgregcal_create_from_date_time', 5, 'second');
                VmIntlCalendar::assertInt32Field($second, 6, 'intlgregcal_create_from_date_time');
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlCalendar::createGregorianFromDate(
            $frame->vmContext,
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        ));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('intlgregcal_create_from_date_time() is not implemented for JIT in this compiler build (issue #20906)');
    }
}
