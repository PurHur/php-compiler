<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/**
 * IntlDateFormatter create/format/parse — ICU pattern subset (#19549, #5201, #3336, #20729).
 *
 * Style-only create (no explicit pattern) resolves CLDR-like date/time patterns for a
 * documented locale set, then formats via {@see icuPatternToPhpFormat()} — php-src
 * dateformat_create.c / dateformat_format.c / udat_open(UDAT_SHORT, …) semantics.
 * Parse / localtime reverse the same mapped subset (php-src dateformat_parse.cpp).
 *
 * php-src: ext/intl/dateformat/dateformat_create.c, dateformat_format.c,
 * dateformat_parse.cpp, dateformat.stub.php
 */
final class VmIntlDateFormatter
{
    public const CLASS_LC = 'intldateformatter';

    public const NONE = -1;
    /** ICU UDAT_PATTERN — PHP 8.4+ class constant (#22623; dateformat.stub.php). */
    public const PATTERN = -2;
    public const FULL = 0;
    public const LONG = 1;
    public const MEDIUM = 2;
    public const SHORT = 3;
    public const RELATIVE_FULL = 128;
    public const RELATIVE_LONG = 129;
    public const RELATIVE_MEDIUM = 130;
    public const RELATIVE_SHORT = 131;
    public const GREGORIAN = 1;
    public const TRADITIONAL = 0;

    /** Narrow no-break space (U+202F) — ICU en_US time patterns before `a`. */
    private const NNBSP = "\u{202F}";

    /** Sentinel embedded via date() literals then replaced with ICU long zone name (#22004). */
    private const LONG_ZONE_MARKER = "\x1EZL\x1E";

    /** ULOC_ACTUAL_LOCALE / ULOC_VALID_LOCALE — php-src Locale::ACTUAL_LOCALE / VALID_LOCALE */
    public const ULOC_ACTUAL_LOCALE = 0;
    public const ULOC_VALID_LOCALE = 1;

    /**
     * Stored when an IntlCalendar object was set via setCalendar(); getCalendar() returns false.
     * php-src dateformat_attrcpp.cpp datefmt_get_calendar.
     */
    public const CALENDAR_FROM_OBJECT = -1;

    /**
     * @var array<int, array{
     *   locale: string,
     *   dateType: int,
     *   timeType: int,
     *   timezone: string,
     *   calendar: int,
     *   pattern: ?string,
     *   lenient: bool,
     *   errorCode: int,
     *   errorMessage: string
     * }>
     */
    private static array $state = [];

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'NONE' => self::NONE,
            // PHP 8.4+ only — withheld on reference / PROFILE=8.2 (#22623)
            ...(CompilerVersion::supportsIntlDateFormatterPatternConst() ? [
                'PATTERN' => self::PATTERN,
            ] : []),
            'FULL' => self::FULL,
            'LONG' => self::LONG,
            'MEDIUM' => self::MEDIUM,
            'SHORT' => self::SHORT,
            'RELATIVE_FULL' => self::RELATIVE_FULL,
            'RELATIVE_LONG' => self::RELATIVE_LONG,
            'RELATIVE_MEDIUM' => self::RELATIVE_MEDIUM,
            'RELATIVE_SHORT' => self::RELATIVE_SHORT,
            'GREGORIAN' => self::GREGORIAN,
            'TRADITIONAL' => self::TRADITIONAL,
        ];
    }

    /**
     * php-src dateformat_create.cpp {@code INTL_UDATE_FMT_OK} (#25205).
     *
     * Accepts UDAT_NONE / PATTERN / FULL–SHORT and RELATIVE_* (incl. bare UDAT_RELATIVE == 128).
     */
    public static function isValidUDateFormatStyle(int $style): bool
    {
        return self::NONE === $style
            || self::PATTERN === $style
            || ($style >= self::FULL && $style <= self::SHORT)
            || ($style >= self::RELATIVE_FULL && $style <= self::RELATIVE_SHORT);
    }

    /**
     * Validate date/time styles for create/__construct; set IntlError on failure (#25205).
     *
     * php-src: ext/intl/dateformat/dateformat_create.cpp — style checks before udat_open.
     */
    public static function validateStylesOrSetError(int $dateType, int $timeType): bool
    {
        if (!self::isValidUDateFormatStyle($dateType)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_create: invalid date format style: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (!self::isValidUDateFormatStyle($timeType)) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_create: invalid time format style: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (self::PATTERN === $dateType && self::PATTERN !== $timeType) {
            // php-src ≤8.2 wording uses UDAT_PATTERN; 8.4+ / PATTERN const gate uses class name.
            $label = CompilerVersion::supportsIntlDateFormatterPatternConst()
                ? 'IntlDateFormatter::PATTERN'
                : 'UDAT_PATTERN';
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_create: time format must be '.$label.' if date format is '.$label.': U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }

        return true;
    }

    /**
     * Shared init for create() / __construct() (#21097; mirrors MessageFormatter #20809).
     *
     * Caller must validate styles via {@see validateStylesOrSetError()} first (#25205).
     *
     * @throws NativeDateInvalidTimeZoneException when $timezone is non-empty and invalid
     */
    public static function initObject(
        ObjectEntry $object,
        string $locale,
        int $dateType,
        int $timeType,
        ?string $timezone,
        int $calendar,
        ?string $pattern
    ): void {
        $tz = null !== $timezone && '' !== $timezone
            ? VmDateTimeNative::validateTimezoneId($timezone)
            : VmDate::defaultTimezoneGet();
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => $locale,
            'dateType' => $dateType,
            'timeType' => $timeType,
            'timezone' => $tz,
            'calendar' => $calendar,
            'pattern' => $pattern,
            'lenient' => true,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
    }

    /**
     * @return ObjectEntry|null null when date/time styles are illegal (#25205)
     *
     * @throws NativeDateInvalidTimeZoneException when $timezone is non-empty and invalid
     */
    public static function create(
        Context $ctx,
        string $locale,
        int $dateType,
        int $timeType,
        ?string $timezone,
        int $calendar,
        ?string $pattern
    ): ?ObjectEntry {
        if (!self::validateStylesOrSetError($dateType, $timeType)) {
            return null;
        }
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlDateFormatter" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        self::initObject($object, $locale, $dateType, $timeType, $timezone, $calendar, $pattern);

        return $object;
    }

    /**
     * @return string|false
     */
    public static function format(ObjectEntry $formatter, Variable $datetimeArg, Frame $frame)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format: invalid object type for date/time (only IntlCalendar and DateTimeInterface permitted): U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $pattern = self::effectivePattern($state);
        if (null === $pattern || '' === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        // php-src dateformat_format.c — IS_ARRAY → tm_* localtime fields via formatter calendar (#22870).
        $datetimeArg = $datetimeArg->resolveIndirect();
        if (Variable::TYPE_ARRAY === $datetimeArg->type) {
            $resolved = self::resolveFormatInstantFromLocaltimeArray($formatter, $datetimeArg->toArray(), $state);
            if (null === $resolved) {
                return false;
            }
            [$timestamp, $microsecond] = $resolved;
            self::clearObjectError($formatter);
            IntlError::clear();

            return self::formatResolved($state, $pattern, $timestamp, $microsecond);
        }
        $resolved = self::resolveFormatInstant($datetimeArg, $frame->vmContext);
        if (null === $resolved) {
            return false;
        }
        [$timestamp, $microsecond] = $resolved;
        IntlError::clear();

        return self::formatResolved($state, $pattern, $timestamp, $microsecond);
    }

    /**
     * IntlDateFormatter::getPattern() — php-src datefmt_get_pattern (#3336).
     *
     * @return string|false
     */
    public static function getPattern(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_get_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $pattern = self::effectivePattern($state);
        if (null === $pattern) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_get_pattern: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        self::clearObjectError($formatter);

        return $pattern;
    }

    /**
     * IntlDateFormatter::setPattern() / datefmt_set_pattern — php-src dateformat_attr.c (#20850 / #20837).
     */
    public static function setPattern(ObjectEntry $formatter, string $pattern): bool
    {
        if (!isset(self::$state[$formatter->id])) {
            self::fail($formatter, 'datefmt_set_pattern: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        self::$state[$formatter->id]['pattern'] = $pattern;
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * IntlDateFormatter::getLocale() — php-src datefmt_get_locale (#20850).
     *
     * Without a live ICU DateFormat handle, both ACTUAL and VALID return the stored locale
     * (factory locale). Invalid $type → false + U_ILLEGAL_ARGUMENT_ERROR.
     *
     * @return string|false
     */
    public static function getLocale(ObjectEntry $formatter, int $type = self::ULOC_ACTUAL_LOCALE)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_locale: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        if (self::ULOC_ACTUAL_LOCALE !== $type && self::ULOC_VALID_LOCALE !== $type) {
            self::fail($formatter, 'datefmt_get_locale: bad type: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['locale'];
    }

    /**
     * IntlDateFormatter::getDateType() — php-src datefmt_get_datetype (#20850).
     *
     * @return int|false
     */
    public static function getDateType(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_datetype: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['dateType'];
    }

    /**
     * IntlDateFormatter::getTimeType() — php-src datefmt_get_timetype (#20850).
     *
     * @return int|false
     */
    public static function getTimeType(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_timetype: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['timeType'];
    }

    /**
     * IntlDateFormatter::isLenient() — php-src datefmt_is_lenient (#20850).
     */
    public static function isLenient(ObjectEntry $formatter): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            return false;
        }

        return $state['lenient'];
    }

    /**
     * IntlDateFormatter::setLenient() — php-src datefmt_set_lenient (#20850).
     */
    public static function setLenient(ObjectEntry $formatter, bool $lenient): void
    {
        if (!isset(self::$state[$formatter->id])) {
            return;
        }
        self::$state[$formatter->id]['lenient'] = $lenient;
        self::clearObjectError($formatter);
        IntlError::clear();
    }

    /**
     * IntlDateFormatter::getCalendar() — php-src datefmt_get_calendar (#20850).
     *
     * @return int|false
     */
    public static function getCalendar(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_calendar: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        if (self::CALENDAR_FROM_OBJECT === $state['calendar']) {
            self::clearObjectError($formatter);
            IntlError::clear();

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['calendar'];
    }

    /**
     * IntlDateFormatter::setCalendar() — php-src datefmt_set_calendar (#20850).
     *
     * Int/null keeps the formatter timezone; IntlCalendar adopts the calendar's timezone
     * and marks calendar type as {@see CALENDAR_FROM_OBJECT}.
     */
    public static function setCalendar(ObjectEntry $formatter, Variable $calendarArg, Context $ctx): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_set_calendar: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        $var = $calendarArg->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            self::$state[$formatter->id]['calendar'] = self::GREGORIAN;
        } elseif (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if (!VmIntlCalendar::isCalendarObject($obj)) {
                throw new \TypeError(\sprintf(
                    'IntlDateFormatter::setCalendar(): Argument #1 ($calendar) must be of type IntlCalendar|int|null, %s given',
                    $obj->class->name
                ));
            }
            $tz = VmIntlCalendar::getTimeZoneObject($obj, $ctx);
            if (false === $tz) {
                self::fail($formatter, 'datefmt_set_calendar: bad calendar: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

                return false;
            }
            self::$state[$formatter->id]['calendar'] = self::CALENDAR_FROM_OBJECT;
            self::$state[$formatter->id]['timezone'] = VmIntlTimeZone::idOf($tz);
        } else {
            self::$state[$formatter->id]['calendar'] = self::coerceIntArg(
                $var,
                'IntlDateFormatter::setCalendar',
                0,
                'calendar'
            );
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    /**
     * IntlDateFormatter::getTimeZoneId() — php-src datefmt_get_timezone_id (#20850).
     *
     * @return string|false
     */
    public static function getTimeZoneId(ObjectEntry $formatter)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_timezone_id: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $state['timezone'];
    }

    /**
     * IntlDateFormatter::getCalendarObject() — php-src datefmt_get_calendar_object (#20850).
     *
     * @return ObjectEntry|false|null
     */
    public static function getCalendarObject(ObjectEntry $formatter, Context $ctx)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_calendar_object: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        if (!isset($ctx->classes[VmIntlCalendar::CLASS_LC])) {
            VmIntlCalendar::registerClass($ctx);
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return VmIntlCalendar::createInstance($ctx, $state['timezone'], $state['locale']);
    }

    /**
     * IntlDateFormatter::parse() / parseToCalendar() — php-src datefmt_parse (#20729).
     *
     * @param-out int|null $offset
     *
     * @return int|float|false
     */
    public static function parse(ObjectEntry $formatter, string $text, ?int &$offset, bool $updateCalendar = false)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_parse: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        $pattern = self::effectivePattern($state);
        if (null === $pattern || '' === $pattern) {
            self::fail(
                $formatter,
                'datefmt_parse: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR',
                IntlError::U_ILLEGAL_ARGUMENT_ERROR
            );

            return false;
        }
        $start = $offset ?? 0;
        if ($start < 0 || $start > \strlen($text)) {
            return false;
        }
        $slice = \substr($text, $start);
        $matched = self::matchPhpFormatPrefix(self::icuPatternToPhpFormat($pattern), $slice);
        if (null === $matched) {
            self::fail($formatter, 'Date parsing failed: U_PARSE_ERROR', IntlError::U_PARSE_ERROR);

            return false;
        }
        $year = $matched['year'];
        $month = $matched['month'];
        $day = $matched['day'];
        if (false === $year || false === $month || false === $day) {
            self::fail($formatter, 'Date parsing failed: U_PARSE_ERROR', IntlError::U_PARSE_ERROR);

            return false;
        }
        $hour = false === $matched['hour'] ? 0 : $matched['hour'];
        $minute = false === $matched['minute'] ? 0 : $matched['minute'];
        $second = false === $matched['second'] ? 0 : $matched['second'];
        $tz = $state['timezone'];
        try {
            $timestamp = self::mktimeInTimezone(
                (int) $year,
                (int) $month,
                (int) $day,
                $hour,
                $minute,
                $second,
                $tz
            );
        } catch (\Throwable) {
            self::fail($formatter, 'Date parsing failed: U_PARSE_ERROR', IntlError::U_PARSE_ERROR);

            return false;
        }
        $offset = $start + $matched['consumed'];
        // updateCalendar mirrors php-src parseToCalendar (udat_parseCalendar); timestamp result is identical.
        unset($updateCalendar);
        self::clearObjectError($formatter);
        IntlError::clear();

        return $timestamp;
    }

    /**
     * IntlDateFormatter::localtime() — php-src datefmt_localtime (#20729).
     *
     * @param-out int|null $offset
     *
     * @return HashTable|false
     */
    public static function localtime(ObjectEntry $formatter, string $text, ?int &$offset)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_localtime: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        $pattern = self::effectivePattern($state);
        if (null === $pattern || '' === $pattern) {
            self::fail(
                $formatter,
                'datefmt_localtime: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR',
                IntlError::U_ILLEGAL_ARGUMENT_ERROR
            );

            return false;
        }
        $start = $offset ?? 0;
        if ($start < 0 || $start > \strlen($text)) {
            return false;
        }
        $slice = \substr($text, $start);
        $matched = self::matchPhpFormatPrefix(self::icuPatternToPhpFormat($pattern), $slice);
        if (null === $matched) {
            self::fail($formatter, 'Date parsing failed: U_PARSE_ERROR', IntlError::U_PARSE_ERROR);

            return false;
        }
        $year = $matched['year'];
        $month = $matched['month'];
        $day = $matched['day'];
        if (false === $year || false === $month || false === $day) {
            self::fail($formatter, 'Date parsing failed: U_PARSE_ERROR', IntlError::U_PARSE_ERROR);

            return false;
        }
        // php-src: Calendar starts at "now"; parse overwrites only pattern fields (#25228).
        // Unset time fields keep the formatter-timezone wall clock (not forced zero).
        $nowH = 0;
        $nowM = 0;
        $nowS = 0;
        $needNow = false === $matched['hour'] || false === $matched['minute'] || false === $matched['second'];
        if ($needNow) {
            [$nowH, $nowM, $nowS] = self::wallClockHmsInTimezone($state['timezone']);
        }
        $hour = false === $matched['hour'] ? $nowH : (int) $matched['hour'];
        $minute = false === $matched['minute'] ? $nowM : (int) $matched['minute'];
        $second = false === $matched['second'] ? $nowS : (int) $matched['second'];
        try {
            $timestamp = self::mktimeInTimezone(
                (int) $year,
                (int) $month,
                (int) $day,
                $hour,
                $minute,
                $second,
                $state['timezone']
            );
        } catch (\Throwable) {
            self::fail($formatter, 'Date parsing failed: U_PARSE_ERROR', IntlError::U_PARSE_ERROR);

            return false;
        }
        $offset = $start + $matched['consumed'];
        $parts = VmDateTimeNative::format($timestamp, 0, $state['timezone'], 's,i,H,Y,j,w,z,n');
        $bits = \explode(',', $parts);
        $ht = new HashTable();
        $fields = [
            'tm_sec' => (int) $bits[0],
            'tm_min' => (int) $bits[1],
            'tm_hour' => (int) $bits[2],
            'tm_year' => ((int) $bits[3]) - 1900,
            'tm_mday' => (int) $bits[4],
            'tm_wday' => (int) $bits[5],
            // ICU UCAL_DAY_OF_YEAR is 1-based; PHP date('z') is 0-based (#25228).
            'tm_yday' => ((int) $bits[6]) + 1,
            'tm_mon' => ((int) $bits[7]) - 1,
            'tm_isdst' => 0,
        ];
        foreach ($fields as $key => $value) {
            $slot = new Variable();
            $slot->int($value);
            $ht->add($key, $slot);
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return $ht;
    }

    /**
     * IntlDateFormatter::getTimeZone() — php-src datefmt_get_timezone (#20729).
     *
     * @return ObjectEntry|false
     */
    public static function getTimeZone(ObjectEntry $formatter, Context $ctx)
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_get_timezone: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        if (!isset($ctx->classes[VmIntlTimeZone::CLASS_LC])) {
            self::fail($formatter, 'datefmt_get_timezone: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        self::clearObjectError($formatter);
        IntlError::clear();

        return VmIntlTimeZone::createFromId($ctx, $state['timezone']);
    }

    /**
     * IntlDateFormatter::setTimeZone() — php-src datefmt_set_timezone (#20729).
     */
    public static function setTimeZone(ObjectEntry $formatter, Variable $timezoneArg, Context $ctx): bool
    {
        $state = self::$state[$formatter->id] ?? null;
        if (null === $state) {
            self::fail($formatter, 'datefmt_set_timezone: bad formatter: U_ILLEGAL_ARGUMENT_ERROR', IntlError::U_ILLEGAL_ARGUMENT_ERROR);

            return false;
        }
        $var = $timezoneArg->resolveIndirect();
        try {
            if (Variable::TYPE_NULL === $var->type) {
                $id = VmDate::defaultTimezoneGet();
            } elseif (Variable::TYPE_OBJECT === $var->type) {
                $obj = $var->toObject();
                if (VmIntlTimeZone::isTimeZoneObject($obj)) {
                    $id = VmIntlTimeZone::idOf($obj);
                } elseif ('datetimezone' === strtolower($obj->class->name)) {
                    $id = DateTimeSupport::timezoneName($obj);
                } else {
                    throw new \TypeError(\sprintf(
                        'IntlDateFormatter::setTimeZone(): Argument #1 ($timezone) must be of type IntlTimeZone|DateTimeZone|string|null, %s given',
                        $obj->class->name
                    ));
                }
            } else {
                $raw = VmString::coerceStringBuiltinArg($var, 'IntlDateFormatter::setTimeZone', 0, 'timezone');
                $id = '' === $raw ? VmDate::defaultTimezoneGet() : VmDateTimeNative::validateTimezoneId($raw);
            }
        } catch (NativeDateInvalidTimeZoneException) {
            $label = Variable::TYPE_STRING === $var->type ? $var->toString() : '';
            self::fail(
                $formatter,
                "datefmt_set_timezone: No such time zone: '".$label."': U_ILLEGAL_ARGUMENT_ERROR",
                IntlError::U_ILLEGAL_ARGUMENT_ERROR
            );

            return false;
        }
        unset($ctx);
        self::$state[$formatter->id]['timezone'] = $id;
        self::clearObjectError($formatter);
        IntlError::clear();

        return true;
    }

    public static function getErrorCode(ObjectEntry $formatter): int
    {
        $state = self::$state[$formatter->id] ?? null;

        return null === $state ? IntlError::U_ZERO_ERROR : $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $formatter): string
    {
        $state = self::$state[$formatter->id] ?? null;

        return null === $state ? 'U_ZERO_ERROR' : $state['errorMessage'];
    }

    /**
     * @param array{locale: string, dateType: int, timeType: int, timezone: string, calendar: int, pattern: ?string} $state
     */
    public static function effectivePattern(array $state): ?string
    {
        $explicit = $state['pattern'];
        if (null !== $explicit && '' !== $explicit) {
            return $explicit;
        }
        // TRADITIONAL + @calendar= → ICU udat_toPattern (hebrew/islamic/japanese/buddhist) (#22877).
        if (self::usesLocaleCalendarKeyword($state)) {
            $icu = IcuDateFormat::patternFromStyles(
                $state['locale'],
                $state['dateType'],
                $state['timeType'],
                $state['timezone']
            );
            if (null !== $icu && '' !== $icu) {
                return $icu;
            }
        }

        return self::patternFromStyles($state['locale'], $state['dateType'], $state['timeType']);
    }

    /**
     * php-src dateformat_create: calendar TRADITIONAL honors locale {@code @calendar=} (#22877).
     *
     * @param array{locale: string, calendar: int, pattern: ?string, dateType: int, timeType: int, timezone: string} $state
     */
    private static function usesLocaleCalendarKeyword(array $state): bool
    {
        if (self::TRADITIONAL !== (int) $state['calendar']) {
            return false;
        }
        $explicit = $state['pattern'] ?? null;
        if (null !== $explicit && '' !== $explicit) {
            return false;
        }

        return IcuDateFormat::localeHasCalendarKeyword($state['locale']);
    }

    /**
     * @param array{locale: string, dateType: int, timeType: int, timezone: string, calendar: int, pattern: ?string} $state
     */
    private static function formatResolved(
        array $state,
        string $pattern,
        int $timestamp,
        int $microsecond
    ): string {
        if (self::usesLocaleCalendarKeyword($state)) {
            $millis = ($timestamp * 1000.0) + ($microsecond / 1000.0);
            $icu = IcuDateFormat::formatStyles(
                $state['locale'],
                $state['dateType'],
                $state['timeType'],
                $state['timezone'],
                $millis
            );
            if (null !== $icu) {
                return $icu;
            }
        }

        return self::formatIcuPattern($pattern, $timestamp, $microsecond, $state['timezone']);
    }

    /**
     * Resolve ICU SimpleDateFormat pattern from locale + date/time styles (#3336).
     *
     * Tables match ICU/CLDR output observed on Zend PHP 8.2 (en_US / en_GB / de_DE / fr_FR).
     * Unknown locales fall back to en_US patterns (documented subset — not full udat_open).
     */
    public static function patternFromStyles(string $locale, int $dateType, int $timeType): ?string
    {
        $dateType = self::normalizeStyle($dateType);
        $timeType = self::normalizeStyle($timeType);
        $loc = self::normalizeLocaleKey($locale);
        $datePat = self::dateStylePattern($loc, $dateType);
        $timePat = self::timeStylePattern($loc, $timeType);
        if (null === $datePat && null === $timePat) {
            return null;
        }
        if (null === $datePat || '' === $datePat) {
            return $timePat ?? '';
        }
        if (null === $timePat || '' === $timePat) {
            return $datePat;
        }

        return $datePat.self::dateTimeConnector($loc, $dateType).$timePat;
    }

    private static function normalizeStyle(int $style): int
    {
        if ($style >= self::RELATIVE_FULL) {
            return $style - self::RELATIVE_FULL;
        }

        return $style;
    }

    private static function normalizeLocaleKey(string $locale): string
    {
        $locale = str_replace('-', '_', trim($locale));
        // Strip Unicode locale extensions (@calendar=… / -u-ca-…) for style table lookup (#22877).
        $at = strpos($locale, '@');
        if (false !== $at) {
            $locale = substr($locale, 0, $at);
        }
        $uExt = stripos($locale, '_u_');
        if (false !== $uExt) {
            $locale = substr($locale, 0, $uExt);
        }
        if ('' === $locale) {
            return 'en_US';
        }
        $parts = explode('_', $locale);
        $lang = strtolower($parts[0] ?? 'en');
        $region = isset($parts[1]) ? strtoupper($parts[1]) : '';
        $key = '' !== $region ? $lang.'_'.$region : $lang;
        $known = ['en_US', 'en_GB', 'de_DE', 'fr_FR'];
        if (\in_array($key, $known, true)) {
            return $key;
        }
        // Language-only fallbacks used by common short locales.
        return match ($lang) {
            'en' => 'en_US',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            default => 'en_US',
        };
    }

    private static function dateStylePattern(string $loc, int $dateType): ?string
    {
        if (self::NONE === $dateType) {
            return '';
        }
        /** @var array<string, array<int, string>> $table */
        $table = [
            'en_US' => [
                self::FULL => 'EEEE, MMMM d, y',
                self::LONG => 'MMMM d, y',
                self::MEDIUM => 'MMM d, y',
                self::SHORT => 'M/d/yy',
            ],
            'en_GB' => [
                self::FULL => 'EEEE, d MMMM y',
                self::LONG => 'd MMMM y',
                self::MEDIUM => 'd MMM y',
                self::SHORT => 'dd/MM/y',
            ],
            'de_DE' => [
                self::FULL => 'EEEE, d. MMMM y',
                self::LONG => 'd. MMMM y',
                self::MEDIUM => 'dd.MM.y',
                self::SHORT => 'dd.MM.yy',
            ],
            'fr_FR' => [
                self::FULL => 'EEEE d MMMM y',
                self::LONG => 'd MMMM y',
                self::MEDIUM => 'd MMM y',
                self::SHORT => 'dd/MM/y',
            ],
        ];

        return $table[$loc][$dateType] ?? $table['en_US'][$dateType] ?? null;
    }

    private static function timeStylePattern(string $loc, int $timeType): ?string
    {
        if (self::NONE === $timeType) {
            return '';
        }
        $nn = self::NNBSP;
        /** @var array<string, array<int, string>> $table */
        $table = [
            'en_US' => [
                self::FULL => 'h:mm:ss'.$nn.'a zzzz',
                self::LONG => 'h:mm:ss'.$nn.'a z',
                self::MEDIUM => 'h:mm:ss'.$nn.'a',
                self::SHORT => 'h:mm'.$nn.'a',
            ],
            'en_GB' => [
                self::FULL => 'HH:mm:ss zzzz',
                self::LONG => 'HH:mm:ss z',
                self::MEDIUM => 'HH:mm:ss',
                self::SHORT => 'HH:mm',
            ],
            'de_DE' => [
                self::FULL => 'HH:mm:ss zzzz',
                self::LONG => 'HH:mm:ss z',
                self::MEDIUM => 'HH:mm:ss',
                self::SHORT => 'HH:mm',
            ],
            'fr_FR' => [
                self::FULL => 'HH:mm:ss zzzz',
                self::LONG => 'HH:mm:ss z',
                self::MEDIUM => 'HH:mm:ss',
                self::SHORT => 'HH:mm',
            ],
        ];

        return $table[$loc][$timeType] ?? $table['en_US'][$timeType] ?? null;
    }

    /**
     * ICU date+time glue for style pairs (Zend 8.2 / ICU observations).
     */
    private static function dateTimeConnector(string $loc, int $dateType): string
    {
        if ('fr_FR' === $loc && $dateType >= self::MEDIUM) {
            return ' ';
        }
        if ($dateType <= self::LONG) {
            return " 'at' ";
        }

        return ', ';
    }

    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    public static function coerceIntArg(Variable $var, string $function, int $position, string $name): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position + 1,
                $name,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
            return (int) $var->toString();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    public static function coerceOptionalTimezone(Variable $var, string $function, int $position): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if ('datetimezone' === strtolower($obj->class->name)) {
                return DateTimeSupport::timezoneName($obj);
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($timezone) must be of type DateTimeZone|string|null, %s given',
                $function,
                $position + 1,
                $obj->class->name
            ));
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'timezone');
    }

    public static function coerceOptionalCalendar(Variable $var, string $function, int $position): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return self::GREGORIAN;
        }

        return self::coerceIntArg($var, $function, $position, 'calendar');
    }

    public static function coerceOptionalPattern(Variable $var, string $function, int $position): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'pattern');
    }

    public static function coerceBoolArg(Variable $var, string $function, int $position, string $name): bool
    {
        return LocaleLookup::coerceBool($var, $function, $position, $name);
    }

    public static function isFormatterObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * php-src dateformat_format.c `internal_get_timestamp` — associative localtime() array (#22870).
     *
     * Keys: tm_year (years since 1900), tm_mon (0–11), tm_mday, tm_hour, tm_min, tm_sec.
     * Missing keys default to 0 (ICU ucal_setDateTime roll, e.g. mday=0 → prior month).
     *
     * @param array{locale: string, timezone: string, pattern: ?string, datetype: int, timetype: int, calendar: int, errorCode: int, errorMessage: string} $state
     *
     * @return array{0: int, 1: int}|null
     */
    private static function resolveFormatInstantFromLocaltimeArray(
        ObjectEntry $formatter,
        HashTable $hashArr,
        array $state
    ): ?array {
        if (0 === $hashArr->getNumElements()) {
            return null;
        }
        $detail = null;
        $year = self::localtimeArrayElem($hashArr, 'tm_year', $detail) + 1900;
        $month = self::localtimeArrayElem($hashArr, 'tm_mon', $detail);
        $hour = self::localtimeArrayElem($hashArr, 'tm_hour', $detail);
        $minute = self::localtimeArrayElem($hashArr, 'tm_min', $detail);
        $second = self::localtimeArrayElem($hashArr, 'tm_sec', $detail);
        $mday = self::localtimeArrayElem($hashArr, 'tm_mday', $detail);
        if (null !== $detail) {
            // php-src INTL_METHOD_CHECK_STATUS after internal_get_timestamp collapses the detail.
            self::fail(
                $formatter,
                'datefmt_format: date formatting failed: U_ILLEGAL_ARGUMENT_ERROR',
                IntlError::U_ILLEGAL_ARGUMENT_ERROR
            );

            return null;
        }
        try {
            // tm_mon is 0-based (ICU / localtime); mktimeInTimezone expects 1-based month.
            $timestamp = self::mktimeInTimezone(
                $year,
                $month + 1,
                $mday,
                $hour,
                $minute,
                $second,
                $state['timezone']
            );
        } catch (\Throwable) {
            self::fail(
                $formatter,
                'datefmt_format: date formatting failed: U_ILLEGAL_ARGUMENT_ERROR',
                IntlError::U_ILLEGAL_ARGUMENT_ERROR
            );

            return null;
        }

        return [$timestamp, 0];
    }

    /**
     * php-src dateformat_format.c `internal_get_arr_ele` — missing key → 0; non-int / out of int32 → error.
     *
     * @param-out string|null $detail
     */
    private static function localtimeArrayElem(HashTable $hashArr, string $key, ?string &$detail): int
    {
        if (null !== $detail) {
            return 0;
        }
        $ele = $hashArr->find($key);
        if (null === $ele) {
            return 0;
        }
        $ele = $ele->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $ele->type) {
            $detail = \sprintf(
                "datefmt_format: parameter array contains a non-integer element for key '%s'",
                $key
            );

            return 0;
        }
        $value = $ele->toInt();
        if ($value > 2147483647 || $value < -2147483648) {
            $detail = \sprintf(
                'datefmt_format: value %d is out of bounds for a 32-bit integer in key \'%s\'',
                $value,
                $key
            );

            return 0;
        }

        return $value;
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function resolveFormatInstant(Variable $var, ?Context $ctx): ?array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format: invalid PHP type for date: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return [$var->toInt(), 0];
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return [(int) $var->toFloat(), 0];
        }
        if (Variable::TYPE_OBJECT === $var->type && null !== $ctx) {
            $obj = $var->toObject();
            if (VmIntlCalendar::isCalendarObject($obj)) {
                $ms = VmIntlCalendar::getTime($obj);
                if (false === $ms) {
                    IntlError::set(
                        IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                        'datefmt_format: invalid object type for date/time (only IntlCalendar and DateTimeInterface permitted): U_ILLEGAL_ARGUMENT_ERROR'
                    );

                    return null;
                }
                $sec = (int) floor($ms / 1000.0);
                $usec = ((int) round($ms - ($sec * 1000.0))) * 1000;

                return [$sec, $usec];
            }
            try {
                $dt = DateTimeSupport::requireDateTimeInterface(
                    $var,
                    'IntlDateFormatter::format()',
                    $ctx
                );

                return [DateTimeSupport::getTimestamp($dt), DateTimeSupport::getMicrosecond($dt)];
            } catch (\TypeError) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'datefmt_format: invalid object type for date/time (only IntlCalendar and DateTimeInterface permitted): U_ILLEGAL_ARGUMENT_ERROR'
                );

                return null;
            }
        }
        IntlError::set(
            IntlError::U_ILLEGAL_ARGUMENT_ERROR,
            'datefmt_format: invalid PHP type for date: U_ILLEGAL_ARGUMENT_ERROR'
        );

        return null;
    }

    /**
     * IntlDateFormatter::formatObject() / datefmt_format_object() — php-src dateformat_format_object.cpp (#20813).
     *
     * @param array{0: int, 1: int}|int|string|null $format dateStyle, [dateStyle, timeStyle], pattern, or null=MEDIUM/MEDIUM
     *
     * @return string|false
     */
    public static function formatObject(
        Context $ctx,
        Variable $datetimeArg,
        array|int|string|null $format,
        ?string $locale
    ) {
        $resolved = self::resolveFormatInstant($datetimeArg, $ctx);
        if (null === $resolved) {
            return false;
        }
        [$timestamp, $microsecond] = $resolved;
        $locale = null !== $locale && '' !== $locale ? $locale : VmLocale::getDefault();
        $timezone = self::timezoneFromDatetimeArg($datetimeArg, $ctx) ?? VmDate::defaultTimezoneGet();
        $dateType = self::MEDIUM;
        $timeType = self::MEDIUM;
        $pattern = null;
        if (null === $format) {
            // defaults
        } elseif (\is_int($format)) {
            $dateType = $format;
            $timeType = $format;
        } elseif (\is_string($format)) {
            $pattern = $format;
            $dateType = self::NONE;
            $timeType = self::NONE;
        } else {
            if (2 !== \count($format)) {
                IntlError::set(
                    IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                    'datefmt_format_object: bad format; if array, it must have two elements: U_ILLEGAL_ARGUMENT_ERROR'
                );

                return false;
            }
            $vals = array_values($format);
            $dateType = (int) $vals[0];
            $timeType = (int) $vals[1];
        }
        $state = [
            'locale' => $locale,
            'dateType' => $dateType,
            'timeType' => $timeType,
            'timezone' => $timezone,
            'calendar' => self::GREGORIAN,
            'pattern' => $pattern,
        ];
        $effective = self::effectivePattern($state);
        if (null === $effective || '' === $effective) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datefmt_format_object: no date/time pattern available for locale/styles: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return self::formatIcuPattern($effective, $timestamp, $microsecond, $timezone);
    }

    /**
     * Format an ICU SimpleDateFormat subset, substituting ICU long zone names for zzzz (#22004).
     *
     * Short z..zzz still use PHP date() `T` (abbr). Long zzzz cannot use PHP `e` (Olson ID);
     * a sentinel is formatted then replaced with {@see VmIntlTimeZone::displayNameForId()}.
     */
    private static function formatIcuPattern(
        string $pattern,
        int $timestamp,
        int $microsecond,
        string $timezone
    ): string {
        $phpFormat = self::icuPatternToPhpFormat($pattern, true);
        $out = VmDateTimeNative::format($timestamp, $microsecond, $timezone, $phpFormat);
        if (!str_contains($out, self::LONG_ZONE_MARKER)) {
            return $out;
        }
        // Prefer abbr (`T`) over date() `I` — VmDateTimeNative `I` is not DST-reliable (#22004).
        $abbrNow = VmDateTimeNative::format($timestamp, 0, $timezone, 'T');
        $meta = VmIntlTimeZone::offsetMeta($timezone);
        $isDst = $meta['useDst']
            && '' !== $abbrNow
            && $abbrNow === $meta['abbrDst']
            && $meta['abbrDst'] !== $meta['abbrStd'];
        $name = VmIntlTimeZone::displayNameForId(
            $timezone,
            $isDst,
            VmIntlTimeZone::DISPLAY_LONG
        );
        if (!\is_string($name) || '' === $name) {
            $name = $timezone;
        }

        return str_replace(self::LONG_ZONE_MARKER, $name, $out);
    }

    private static function timezoneFromDatetimeArg(Variable $var, Context $ctx): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return null;
        }
        $obj = $var->toObject();
        if (VmIntlCalendar::isCalendarObject($obj)) {
            $tz = VmIntlCalendar::getTimeZoneObject($obj, $ctx);
            if (false === $tz) {
                return null;
            }

            return VmIntlTimeZone::idOf($tz);
        }
        try {
            $dt = DateTimeSupport::requireDateTimeInterface($var, 'IntlDateFormatter::formatObject()', $ctx);

            return DateTimeSupport::timezoneName(DateTimeSupport::getTimezoneObject($dt, $ctx));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map a small ICU SimpleDateFormat subset to PHP date() tokens (#19549).
     *
     * Supported: y/yy/yyyy, M/MM/MMM/MMMM, d/dd, E/EEE/EEEE, H/HH, h/hh, m/mm, s/ss, a, z/zzzz,
     * and literal punctuation (including U+202F).
     *
     * @param bool $forFormat when true, zzzz becomes {@see LONG_ZONE_MARKER} for post-substitution;
     *                        parse keeps PHP `e` so matchPhpFormatPrefix can consume zone text
     */
    public static function icuPatternToPhpFormat(string $pattern, bool $forFormat = false): string
    {
        $out = '';
        $len = \strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ("'" === $ch) {
                $i++;
                $literal = '';
                while ($i < $len) {
                    if ("'" === $pattern[$i]) {
                        if ($i + 1 < $len && "'" === $pattern[$i + 1]) {
                            $literal .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $literal .= $pattern[$i];
                    $i++;
                }
                $out .= self::escapePhpDateLiteral($literal);
                continue;
            }
            if (ctype_alpha($ch)) {
                $run = $ch;
                $i++;
                while ($i < $len && $pattern[$i] === $ch) {
                    $run .= $pattern[$i];
                    $i++;
                }
                $out .= self::mapIcuRun($run, $forFormat);
                continue;
            }
            $out .= self::escapePhpDateLiteral($ch);
            $i++;
        }

        return $out;
    }

    private static function mapIcuRun(string $run, bool $forFormat = false): string
    {
        $ch = $run[0];
        $n = \strlen($run);

        return match ($ch) {
            'y' => match (true) {
                $n >= 4 => 'Y',
                2 === $n => 'y',
                default => 'Y',
            },
            'M' => match (true) {
                $n >= 4 => 'F',
                3 === $n => 'M',
                2 === $n => 'm',
                default => 'n',
            },
            'd' => $n >= 2 ? 'd' : 'j',
            'E' => $n >= 4 ? 'l' : 'D',
            'H' => $n >= 2 ? 'H' : 'G',
            'h' => $n >= 2 ? 'h' : 'g',
            'm' => 'i',
            's' => 's',
            'a' => 'A',
            // ICU zone: z..zzz ≈ short (PHP T); zzzz ≈ long display name (#3336 / #22004).
            'z' => $n >= 4
                ? ($forFormat ? self::escapePhpDateLiteral(self::LONG_ZONE_MARKER) : 'e')
                : 'T',
            default => self::escapePhpDateLiteral($run),
        };
    }

    private static function escapePhpDateLiteral(string $literal): string
    {
        if ('' === $literal) {
            return '';
        }
        // PHP date() treats backslash-escaped ASCII as literals.
        $out = '';
        $len = \strlen($literal);
        for ($i = 0; $i < $len; $i++) {
            $c = $literal[$i];
            if (ctype_alpha($c)) {
                $out .= '\\'.$c;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /**
     * Match a PHP date()-style format as a prefix of $time (ICU allows trailing text).
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   consumed: int
     * }|null
     */
    private static function matchPhpFormatPrefix(string $format, string $time): ?array
    {
        $pos = 0;
        $timeLen = \strlen($time);
        $components = [
            'year' => false,
            'month' => false,
            'day' => false,
            'hour' => false,
            'minute' => false,
            'second' => false,
        ];
        $formatLen = \strlen($format);
        for ($i = 0; $i < $formatLen; ++$i) {
            // ICU treats U+0020 and U+202F (NNBSP before en_US "a") as equivalent (#23960).
            $fmtWs = self::whitespaceByteLen($format, $i, $formatLen);
            if (0 !== $fmtWs) {
                $inWs = self::whitespaceByteLen($time, $pos, $timeLen);
                if (0 === $inWs) {
                    return null;
                }
                $i += $fmtWs - 1; // for-loop increments once more
                $pos += $inWs;
                continue;
            }
            $fc = $format[$i];
            if ('\\' === $fc) {
                if ($i + 1 >= $formatLen) {
                    return null;
                }
                $literal = $format[++$i];
                if ($pos >= $timeLen || $time[$pos] !== $literal) {
                    return null;
                }
                ++$pos;
                continue;
            }
            switch ($fc) {
                case 'Y':
                    $digits = self::readDigits($time, $pos, 4, 4);
                    if (null === $digits) {
                        return null;
                    }
                    $components['year'] = (int) $digits;
                    break;
                case 'y':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $yy = (int) $digits;
                    $components['year'] = $yy >= 69 ? 1900 + $yy : 2000 + $yy;
                    break;
                case 'm':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['month'] = (int) $digits;
                    break;
                case 'n':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['month'] = (int) $digits;
                    break;
                case 'd':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['day'] = (int) $digits;
                    break;
                case 'j':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['day'] = (int) $digits;
                    break;
                case 'H':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['hour'] = (int) $digits;
                    break;
                case 'G':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['hour'] = (int) $digits;
                    break;
                case 'h':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['hour'] = self::hour12To24((int) $digits, $components);
                    break;
                case 'g':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['hour'] = self::hour12To24((int) $digits, $components);
                    break;
                case 'i':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['minute'] = (int) $digits;
                    break;
                case 's':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (null === $digits) {
                        return null;
                    }
                    $components['second'] = (int) $digits;
                    break;
                case 'A':
                case 'a':
                    if ($pos + 2 > $timeLen) {
                        return null;
                    }
                    $ampm = \strtoupper(\substr($time, $pos, 2));
                    if ('AM' !== $ampm && 'PM' !== $ampm) {
                        return null;
                    }
                    $pos += 2;
                    if (false !== $components['hour']) {
                        $h = (int) $components['hour'];
                        if ('PM' === $ampm && $h < 12) {
                            $components['hour'] = $h + 12;
                        } elseif ('AM' === $ampm && 12 === $h) {
                            $components['hour'] = 0;
                        }
                    }
                    break;
                case 'F':
                    $name = self::readMonthName($time, $pos, true);
                    if (null === $name) {
                        return null;
                    }
                    $components['month'] = $name;
                    break;
                case 'M':
                    $name = self::readMonthName($time, $pos, false);
                    if (null === $name) {
                        return null;
                    }
                    $components['month'] = $name;
                    break;
                case 'l':
                case 'D':
                    // Weekday names — consume token; date fields come from Y/m/d.
                    if (null === self::readWeekdayName($time, $pos, 'l' === $fc)) {
                        return null;
                    }
                    break;
                case 'e':
                case 'T':
                    // Timezone display tokens are format-only; consume abbr, Olson ID, or ICU long
                    // names with spaces ("Eastern Standard Time") (#22004).
                    if ($pos >= $timeLen || (!\ctype_alnum($time[$pos]) && '+' !== $time[$pos] && '-' !== $time[$pos])) {
                        return null;
                    }
                    while ($pos < $timeLen && (
                        \ctype_alnum($time[$pos])
                        || ' ' === $time[$pos]
                        || '_' === $time[$pos]
                        || '/' === $time[$pos]
                        || '+' === $time[$pos]
                        || '-' === $time[$pos]
                    )) {
                        ++$pos;
                    }
                    break;
                default:
                    if ($pos >= $timeLen || $time[$pos] !== $fc) {
                        return null;
                    }
                    ++$pos;
            }
        }
        $components['consumed'] = $pos;

        return $components;
    }

    /**
     * Byte length of one ICU-flexible whitespace token at $offset: ASCII space or U+202F NNBSP (#23960).
     */
    private static function whitespaceByteLen(string $s, int $offset, int $len): int
    {
        if ($offset >= $len) {
            return 0;
        }
        if (' ' === $s[$offset]) {
            return 1;
        }
        // U+202F NARROW NO-BREAK SPACE as UTF-8
        if ($offset + 2 < $len
            && "\xE2" === $s[$offset]
            && "\x80" === $s[$offset + 1]
            && "\xAF" === $s[$offset + 2]
        ) {
            return 3;
        }

        return 0;
    }

    /**
     * @param array{hour?: int|false} $components
     */
    private static function hour12To24(int $hour12, array $components): int
    {
        // AM/PM applied when 'A'/'a' is later seen; store raw 1–12 until then.
        return $hour12;
    }

    private static function readDigits(string $time, int &$pos, int $min, int $max): ?string
    {
        $len = \strlen($time);
        $start = $pos;
        $n = 0;
        while ($pos < $len && $n < $max && \ctype_digit($time[$pos])) {
            ++$pos;
            ++$n;
        }
        if ($n < $min) {
            $pos = $start;

            return null;
        }

        return \substr($time, $start, $n);
    }

    private static function readMonthName(string $time, int &$pos, bool $full): ?int
    {
        $fullNames = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        ];
        $shortNames = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
            'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];
        $slice = \strtolower(\substr($time, $pos));
        $table = $full ? $fullNames : $shortNames;
        foreach ($table as $name => $month) {
            if (\str_starts_with($slice, $name)) {
                $pos += \strlen($name);

                return $month;
            }
        }

        return null;
    }

    private static function readWeekdayName(string $time, int &$pos, bool $full): ?string
    {
        $fullNames = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $shortNames = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $slice = \strtolower(\substr($time, $pos));
        foreach ($full ? $fullNames : $shortNames as $name) {
            if (\str_starts_with($slice, $name)) {
                $pos += \strlen($name);

                return $name;
            }
        }

        return null;
    }

    private static function mktimeInTimezone(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tzName
    ): int {
        $iso = \sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        $parsed = VmDateTimeNative::parseDateTime($iso, $tzName);

        return (int) $parsed['timestamp'];
    }

    /**
     * Current wall-clock H:i:s in $tzName — defaults for unset localtime() fields (#25228).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function wallClockHmsInTimezone(string $tzName): array
    {
        $parts = VmDateTimeNative::format(\time(), 0, $tzName, 'H,i,s');
        $bits = \explode(',', $parts);

        return [(int) $bits[0], (int) $bits[1], (int) $bits[2]];
    }

    private static function fail(ObjectEntry $formatter, string $message, int $code): void
    {
        IntlError::set($code, $message);
        if (isset(self::$state[$formatter->id])) {
            self::$state[$formatter->id]['errorCode'] = $code;
            self::$state[$formatter->id]['errorMessage'] = $message;
        }
    }

    private static function clearObjectError(ObjectEntry $formatter): void
    {
        if (!isset(self::$state[$formatter->id])) {
            return;
        }
        self::$state[$formatter->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$formatter->id]['errorMessage'] = 'U_ZERO_ERROR';
    }
}

/** IntlDateFormatter::setPattern() — php-src datefmt_set_pattern (#20850). */
final class IntlDateFormatterSetPattern extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setPattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::setPattern() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::setPattern() called on incompatible object');
        }
        $pattern = VmIntlDateFormatter::coerceOptionalPattern($frame->calledArgs[1], 'IntlDateFormatter::setPattern', 1);
        if (null === $pattern) {
            throw new \TypeError(
                'IntlDateFormatter::setPattern(): Argument #1 ($pattern) must be of type string, null given'
            );
        }
        $ok = VmIntlDateFormatter::setPattern($receiver->toObject(), $pattern);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlDateFormatter::getLocale() — php-src datefmt_get_locale (#20850). */
final class IntlDateFormatterGetLocale extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLocale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getLocale() expects between 0 and 1 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getLocale() called on incompatible object');
        }
        $type = $argc >= 2
            ? VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlDateFormatter::getLocale', 1, 'type')
            : VmIntlDateFormatter::ULOC_ACTUAL_LOCALE;
        $result = VmIntlDateFormatter::getLocale($receiver->toObject(), $type);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlDateFormatter::getDateType() — php-src datefmt_get_datetype (#20850). */
final class IntlDateFormatterGetDateType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDateType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getDateType() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getDateType() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getDateType($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** IntlDateFormatter::getTimeType() — php-src datefmt_get_timetype (#20850). */
final class IntlDateFormatterGetTimeType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimeType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getTimeType() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getTimeType() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getTimeType($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** IntlDateFormatter::isLenient() — php-src datefmt_is_lenient (#20850). */
final class IntlDateFormatterIsLenient extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isLenient');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::isLenient() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::isLenient() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlDateFormatter::isLenient($receiver->toObject()));
    }
}

/** IntlDateFormatter::setLenient() — php-src datefmt_set_lenient (#20850). */
final class IntlDateFormatterSetLenient extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setLenient');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::setLenient() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::setLenient() called on incompatible object');
        }
        $lenient = VmIntlDateFormatter::coerceBoolArg($frame->calledArgs[1], 'IntlDateFormatter::setLenient', 1, 'lenient');
        VmIntlDateFormatter::setLenient($receiver->toObject(), $lenient);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }
}

/** IntlDateFormatter::getCalendar() — php-src datefmt_get_calendar (#20850). */
final class IntlDateFormatterGetCalendar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCalendar');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getCalendar() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getCalendar() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getCalendar($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** IntlDateFormatter::setCalendar() — php-src datefmt_set_calendar (#20850). */
final class IntlDateFormatterSetCalendar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setCalendar');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::setCalendar() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::setCalendar() called on incompatible object');
        }
        $ok = VmIntlDateFormatter::setCalendar($receiver->toObject(), $frame->calledArgs[1], $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlDateFormatter::getTimeZoneId() — php-src datefmt_get_timezone_id (#20850). */
final class IntlDateFormatterGetTimeZoneId extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTimeZoneId');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getTimeZoneId() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getTimeZoneId() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getTimeZoneId($receiver->toObject());
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlDateFormatter::getCalendarObject() — php-src datefmt_get_calendar_object (#20850). */
final class IntlDateFormatterGetCalendarObject extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCalendarObject');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDateFormatter::getCalendarObject() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDateFormatter::isFormatterObject($receiver->toObject())) {
            throw new \Error('IntlDateFormatter::getCalendarObject() called on incompatible object');
        }
        $result = VmIntlDateFormatter::getCalendarObject($receiver->toObject(), $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($result);
    }
}
