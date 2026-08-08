<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPLLVM\Value;

/** datefmt_create() — procedural IntlDateFormatter::create (#20837). */
final class datefmt_create extends Internal
{
    public function __construct()
    {
        parent::__construct('datefmt_create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'datefmt_create() expects between 1 and 6 arguments, %d given',
                $argc
            ));
        }
        $locale = VmIntlDateFormatter::coerceLocaleArg($frame->calledArgs[0], 'datefmt_create', 0);
        $dateType = $argc >= 2
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'datefmt_create', 1, 'dateType')
            : VmIntlDateFormatter::FULL;
        $timeType = $argc >= 3
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'datefmt_create', 2, 'timeType')
            : VmIntlDateFormatter::FULL;
        $timezone = $argc >= 4
            ? VmIntlDateFormatter::coerceOptionalTimezone($frame->calledArgs[3], 'datefmt_create', 3)
            : null;
        $calendar = $argc >= 5
            ? VmIntlDateFormatter::coerceOptionalCalendar($frame->calledArgs[4], 'datefmt_create', 4)
            : VmIntlDateFormatter::GREGORIAN;
        $pattern = $argc >= 6
            ? VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[5], 'datefmt_create', 5)
            : null;

        if (null === $frame->returnVar) {
            return;
        }
        try {
            $object = VmIntlDateFormatter::create(
                $frame->vmContext,
                $locale,
                $dateType,
                $timeType,
                $timezone,
                $calendar,
                $pattern
            );
        } catch (NativeDateInvalidTimeZoneException $e) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                "datefmt_create: No such time zone: '".$timezone."': U_ILLEGAL_ARGUMENT_ERROR"
            );
            $frame->returnVar->null();

            return;
        }
        // Illegal styles → null + IntlError already set (#25205).
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        IntlError::clear();
        $frame->returnVar->object($object);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIntlDateFormatterCreate::invoke($context, ...$args);
    }
}
