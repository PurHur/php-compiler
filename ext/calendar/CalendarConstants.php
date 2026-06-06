<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * CAL_* calendar identifiers (php-src ext/calendar/calendar.c register_calendar_symbols; #7133).
 *
 * Algorithm parity tracked in #3742, #6759.
 */
final class CalendarConstants
{
    public const CAL_GREGORIAN = 0;
    public const CAL_JULIAN = 1;
    public const CAL_JEWISH = 2;
    public const CAL_FRENCH = 3;
    public const CAL_NUM_CALS = 4;

    public const CAL_DOW_DAYNO = 0;
    public const CAL_DOW_LONG = 1;
    public const CAL_DOW_SHORT = 2;

    public const CAL_MONTH_GREGORIAN_SHORT = 0;
    public const CAL_MONTH_GREGORIAN_LONG = 1;
    public const CAL_MONTH_JULIAN_SHORT = 2;
    public const CAL_MONTH_JULIAN_LONG = 3;
    public const CAL_MONTH_JEWISH = 4;
    public const CAL_MONTH_FRENCH = 5;

    public const CAL_EASTER_DEFAULT = 0;
    public const CAL_EASTER_ROMAN = 1;
    public const CAL_EASTER_ALWAYS_GREGORIAN = 2;
    public const CAL_EASTER_ALWAYS_JULIAN = 3;

    public const CAL_JEWISH_ADD_ALAFIM_GERESH = 0x2;
    public const CAL_JEWISH_ADD_ALAFIM = 0x4;
    public const CAL_JEWISH_ADD_GERESHAYIM = 0x8;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'CAL_GREGORIAN' => self::CAL_GREGORIAN,
            'CAL_JULIAN' => self::CAL_JULIAN,
            'CAL_JEWISH' => self::CAL_JEWISH,
            'CAL_FRENCH' => self::CAL_FRENCH,
            'CAL_NUM_CALS' => self::CAL_NUM_CALS,
            'CAL_DOW_DAYNO' => self::CAL_DOW_DAYNO,
            'CAL_DOW_LONG' => self::CAL_DOW_LONG,
            'CAL_DOW_SHORT' => self::CAL_DOW_SHORT,
            'CAL_MONTH_GREGORIAN_SHORT' => self::CAL_MONTH_GREGORIAN_SHORT,
            'CAL_MONTH_GREGORIAN_LONG' => self::CAL_MONTH_GREGORIAN_LONG,
            'CAL_MONTH_JULIAN_SHORT' => self::CAL_MONTH_JULIAN_SHORT,
            'CAL_MONTH_JULIAN_LONG' => self::CAL_MONTH_JULIAN_LONG,
            'CAL_MONTH_JEWISH' => self::CAL_MONTH_JEWISH,
            'CAL_MONTH_FRENCH' => self::CAL_MONTH_FRENCH,
            'CAL_EASTER_DEFAULT' => self::CAL_EASTER_DEFAULT,
            'CAL_EASTER_ROMAN' => self::CAL_EASTER_ROMAN,
            'CAL_EASTER_ALWAYS_GREGORIAN' => self::CAL_EASTER_ALWAYS_GREGORIAN,
            'CAL_EASTER_ALWAYS_JULIAN' => self::CAL_EASTER_ALWAYS_JULIAN,
            'CAL_JEWISH_ADD_ALAFIM_GERESH' => self::CAL_JEWISH_ADD_ALAFIM_GERESH,
            'CAL_JEWISH_ADD_ALAFIM' => self::CAL_JEWISH_ADD_ALAFIM,
            'CAL_JEWISH_ADD_GERESHAYIM' => self::CAL_JEWISH_ADD_GERESHAYIM,
        ];
    }
}
