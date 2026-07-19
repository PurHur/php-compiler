<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
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

    /**
     * @var array<int, array{
     *   locale: string,
     *   dateType: int,
     *   timeType: int,
     *   timezone: string,
     *   calendar: int,
     *   pattern: ?string,
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

    public static function create(
        Context $ctx,
        string $locale,
        int $dateType,
        int $timeType,
        ?string $timezone,
        int $calendar,
        ?string $pattern
    ): ObjectEntry {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlDateFormatter" not found');
        }
        $tz = null !== $timezone && '' !== $timezone
            ? VmDateTimeNative::validateTimezoneId($timezone)
            : VmDate::defaultTimezoneGet();
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => $locale,
            'dateType' => $dateType,
            'timeType' => $timeType,
            'timezone' => $tz,
            'calendar' => $calendar,
            'pattern' => $pattern,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];

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
        $resolved = self::resolveFormatInstant($datetimeArg, $frame->vmContext);
        if (null === $resolved) {
            return false;
        }
        [$timestamp, $microsecond] = $resolved;
        IntlError::clear();
        $phpFormat = self::icuPatternToPhpFormat($pattern);

        return VmDateTimeNative::format($timestamp, $microsecond, $state['timezone'], $phpFormat);
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
        $hour = false === $matched['hour'] ? 0 : $matched['hour'];
        $minute = false === $matched['minute'] ? 0 : $matched['minute'];
        $second = false === $matched['second'] ? 0 : $matched['second'];
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
            'tm_yday' => (int) $bits[6],
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

        return self::patternFromStyles($state['locale'], $state['dateType'], $state['timeType']);
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

    public static function isFormatterObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
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
     * Map a small ICU SimpleDateFormat subset to PHP date() tokens (#19549).
     *
     * Supported: y/yy/yyyy, M/MM/MMM/MMMM, d/dd, E/EEE/EEEE, H/HH, h/hh, m/mm, s/ss, a, z/zzzz,
     * and literal punctuation (including U+202F).
     */
    public static function icuPatternToPhpFormat(string $pattern): string
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
                $out .= self::mapIcuRun($run);
                continue;
            }
            $out .= self::escapePhpDateLiteral($ch);
            $i++;
        }

        return $out;
    }

    private static function mapIcuRun(string $run): string
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
            // ICU zone: z..zzz ≈ short; zzzz ≈ long — PHP date() only has T / e (#3336 subset).
            'z' => $n >= 4 ? 'e' : 'T',
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
                    // Timezone display tokens are format-only in this subset; reject unexpected letters.
                    if ($pos >= $timeLen || (!\ctype_alnum($time[$pos]) && '+' !== $time[$pos] && '-' !== $time[$pos])) {
                        return null;
                    }
                    while ($pos < $timeLen && (
                        \ctype_alnum($time[$pos])
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
