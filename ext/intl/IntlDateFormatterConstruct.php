<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPCompiler\VM\Variable;

/**
 * IntlDateFormatter::__construct() — same init as create() (#21097).
 *
 * php-src: ext/intl/dateformat/dateformat_class.c / dateformat.stub.php
 */
final class IntlDateFormatterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // calledArgs[0] = $this; user-visible args: locale (+ optional date/time/tz/calendar/pattern)
        if ($argc < 2 || $argc > 7) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::__construct() expects between 1 and 6 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::__construct() called on incompatible object');
        }
        $locale = VmIntlDateFormatter::coerceLocaleArg($frame->calledArgs[1], 'IntlDateFormatter::__construct', 0);
        $dateType = $argc >= 3
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[2], 'IntlDateFormatter::__construct', 1, 'dateType')
            : VmIntlDateFormatter::FULL;
        $timeType = $argc >= 4
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[3], 'IntlDateFormatter::__construct', 2, 'timeType')
            : VmIntlDateFormatter::FULL;
        $timezone = $argc >= 5
            ? VmIntlDateFormatter::coerceOptionalTimezone($frame->calledArgs[4], 'IntlDateFormatter::__construct', 3)
            : null;
        $calendar = $argc >= 6
            ? VmIntlDateFormatter::coerceOptionalCalendar($frame->calledArgs[5], 'IntlDateFormatter::__construct', 4)
            : VmIntlDateFormatter::GREGORIAN;
        $pattern = $argc >= 7
            ? VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[6], 'IntlDateFormatter::__construct', 5)
            : null;

        // php-src datefmt_ctor FAILURE + EH_THROW → IntlException (#25205).
        if (!VmIntlDateFormatter::validateStylesOrSetError($dateType, $timeType)) {
            throw new \IntlException(IntlError::getMessage());
        }

        try {
            VmIntlDateFormatter::initObject(
                $receiver->toObject(),
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

            return;
        }
        IntlError::clear();
    }
}
