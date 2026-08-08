<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPLLVM\Value;

/**
 * IntlDateFormatter::create() — ICU pattern subset (php-src dateformat_create.c; #19549 / #5201).
 */
final class IntlDateFormatterCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 6) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::create() expects between 1 and 6 arguments, %d given',
                $argc
            ));
        }
        $locale = VmIntlDateFormatter::coerceLocaleArg($frame->calledArgs[0], 'IntlDateFormatter::create', 0);
        $dateType = $argc >= 2
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlDateFormatter::create', 1, 'dateType')
            : VmIntlDateFormatter::FULL;
        $timeType = $argc >= 3
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlDateFormatter::create', 2, 'timeType')
            : VmIntlDateFormatter::FULL;
        $timezone = $argc >= 4
            ? VmIntlDateFormatter::coerceOptionalTimezone($frame->calledArgs[3], 'IntlDateFormatter::create', 3)
            : null;
        $calendar = $argc >= 5
            ? VmIntlDateFormatter::coerceOptionalCalendar($frame->calledArgs[4], 'IntlDateFormatter::create', 4)
            : VmIntlDateFormatter::GREGORIAN;
        $pattern = $argc >= 6
            ? VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[5], 'IntlDateFormatter::create', 5)
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
        // Illegal styles → null + IntlError already set (#25205 / php-src dateformat_create.cpp).
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
