<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * DateTimeZone group constants (ext/date/php_date.c, issue #3504).
 *
 * php-src: PHP_DATE_TIMEZONE_GROUP_* / PHP_DATE_TIMEZONE_PER_COUNTRY
 */
final class DateTimeZoneSupport
{
    public const GROUP_AFRICA = 0x0001;
    public const GROUP_AMERICA = 0x0002;
    public const GROUP_ANTARCTICA = 0x0004;
    public const GROUP_ARCTIC = 0x0008;
    public const GROUP_ASIA = 0x0010;
    public const GROUP_ATLANTIC = 0x0020;
    public const GROUP_AUSTRALIA = 0x0040;
    public const GROUP_EUROPE = 0x0080;
    public const GROUP_INDIAN = 0x0100;
    public const GROUP_PACIFIC = 0x0200;
    public const GROUP_UTC = 0x0400;
    public const GROUP_ALL = 0x07FF;
    public const GROUP_ALL_WITH_BC = 0x0FFF;
    public const GROUP_PER_COUNTRY = 0x1000;

    /** @var array<string, int> lowercase constant name => value */
    private const CLASS_CONSTANTS = [
        'africa' => self::GROUP_AFRICA,
        'america' => self::GROUP_AMERICA,
        'antarctica' => self::GROUP_ANTARCTICA,
        'arctic' => self::GROUP_ARCTIC,
        'asia' => self::GROUP_ASIA,
        'atlantic' => self::GROUP_ATLANTIC,
        'australia' => self::GROUP_AUSTRALIA,
        'europe' => self::GROUP_EUROPE,
        'indian' => self::GROUP_INDIAN,
        'pacific' => self::GROUP_PACIFIC,
        'utc' => self::GROUP_UTC,
        'all' => self::GROUP_ALL,
        'all_with_bc' => self::GROUP_ALL_WITH_BC,
        'per_country' => self::GROUP_PER_COUNTRY,
    ];

    public static function registerClassConstants(ClassEntry $entry): void
    {
        foreach (self::CLASS_CONSTANTS as $name => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $canonical = strtoupper($name);
            $entry->constants[$canonical] = $const;
            $entry->constNames[$canonical] = $canonical;
        }
    }
}
