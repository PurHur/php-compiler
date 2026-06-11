<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

/**
 * Static calendar name tables (php-src ext/calendar/gregor.c, french.c, jewish.c, dow.c).
 */
final class CalendarTables
{
    /** @var list<string> */
    public const GREGOR_MONTH_SHORT = [
        '',
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
    ];

    /** @var list<string> */
    public const GREGOR_MONTH_LONG = [
        '',
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December',
    ];

    /** @var list<string> */
    public const FRENCH_MONTH = [
        '',
        'Vendemiaire',
        'Brumaire',
        'Frimaire',
        'Nivose',
        'Pluviose',
        'Ventose',
        'Germinal',
        'Floreal',
        'Prairial',
        'Messidor',
        'Thermidor',
        'Fructidor',
        'Extra',
    ];

    /** @var list<string> */
    public const JEWISH_MONTH_LEAP = [
        '',
        'Tishri',
        'Heshvan',
        'Kislev',
        'Tevet',
        'Shevat',
        'Adar I',
        'Adar II',
        'Nisan',
        'Iyyar',
        'Sivan',
        'Tammuz',
        'Av',
        'Elul',
    ];

    /** @var list<int> */
    public const JEWISH_MONTHS_PER_YEAR = [
        12, 12, 13, 12, 12, 13, 12, 13, 12, 12, 13, 12, 12, 13, 12, 12, 13, 12, 13,
    ];

    /** @var list<string> */
    public const DAY_SHORT = [
        'Sun',
        'Mon',
        'Tue',
        'Wed',
        'Thu',
        'Fri',
        'Sat',
    ];

    /** @var list<string> */
    public const DAY_LONG = [
        'Sunday',
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
    ];

    /**
     * @return array{
     *     name: string,
     *     symbol: string,
     *     numMonths: int,
     *     maxDaysInMonth: int,
     *     monthShort: list<string>,
     *     monthLong: list<string>
     * }
     */
    public static function calendarMeta(int $cal): array
    {
        return match ($cal) {
            CalendarConstants::CAL_GREGORIAN => [
                'name' => 'Gregorian',
                'symbol' => 'CAL_GREGORIAN',
                'numMonths' => 12,
                'maxDaysInMonth' => 31,
                'monthShort' => self::GREGOR_MONTH_SHORT,
                'monthLong' => self::GREGOR_MONTH_LONG,
            ],
            CalendarConstants::CAL_JULIAN => [
                'name' => 'Julian',
                'symbol' => 'CAL_JULIAN',
                'numMonths' => 12,
                'maxDaysInMonth' => 31,
                'monthShort' => self::GREGOR_MONTH_SHORT,
                'monthLong' => self::GREGOR_MONTH_LONG,
            ],
            CalendarConstants::CAL_JEWISH => [
                'name' => 'Jewish',
                'symbol' => 'CAL_JEWISH',
                'numMonths' => 13,
                'maxDaysInMonth' => 30,
                'monthShort' => self::JEWISH_MONTH_LEAP,
                'monthLong' => self::JEWISH_MONTH_LEAP,
            ],
            CalendarConstants::CAL_FRENCH => [
                'name' => 'French',
                'symbol' => 'CAL_FRENCH',
                'numMonths' => 13,
                'maxDaysInMonth' => 30,
                'monthShort' => self::FRENCH_MONTH,
                'monthLong' => self::FRENCH_MONTH,
            ],
            default => throw new \LogicException(
                'Calendar ID '.$cal.' is not implemented in this compiler build (issue #3742)'
            ),
        };
    }
}
