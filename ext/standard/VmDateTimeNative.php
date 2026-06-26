<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\DateTimeZoneSupport;
use PHPCompiler\VM\NativeDateMalformedStringException;

/**
 * Native DateTime/DateTimeZone semantics without host Zend \\DateTime (issue #6164).
 * TZ switching via {@see VmEnv} libc FFI — no host Zend env builtins (#8086).
 * zone.tab reads via {@see VmFs::file()} / {@see VmFsReadNative} — no host \\file() (#8529).
 *
 * php-src ref: ext/date/php_datetime.c, ext/date/lib/timelib.c — parsing, formatting, offsets.
 * Thin libc FFI for mktime/localtime/timegm; timezone IDs validated via zoneinfo files.
 */
final class VmDateTimeNative
{
    private const ZONEINFO_ROOT = '/usr/share/zoneinfo';

    private const FORMAT_OUT_BYTES = 256;

    private static ?\FFI $ffi = null;

    /** @var list<string>|null */
    private static ?array $zoneIdentifiers = null;

    /** @var list<array{country: string, id: string}>|null */
    private static ?array $zoneTabEntries = null;

    private static int $withTimezoneDepth = 0;

    /** @var string|false */
    private static string|false $withTimezoneSavedVmEnvTz = false;

    private static ?string $activeLibcTimezone = null;

    /**
     * timezone_identifiers_list() — Olson identifiers from zone.tab (ext/date/php_date.c, #3504).
     *
     * @return list<string>
     */
    public static function timezoneIdentifiersList(
        int $timezoneGroup = DateTimeZoneSupport::GROUP_ALL,
        ?string $countryCode = null
    ): array {
        if (DateTimeZoneSupport::GROUP_PER_COUNTRY === $timezoneGroup) {
            if (null === $countryCode || 2 !== \strlen($countryCode)) {
                throw new \ValueError(
                    'timezone_identifiers_list(): Argument #2 ($countryCode) must be a two-letter ISO 3166-1 compatible country code '
                    .'when argument #1 ($timezoneGroup) is DateTimeZone::PER_COUNTRY'
                );
            }
            $country = strtoupper($countryCode);
            $ids = [];
            foreach (self::zoneTabEntries() as $entry) {
                if ($entry['country'] === $country) {
                    $ids[] = $entry['id'];
                }
            }
            \sort($ids);

            return $ids;
        }

        if (DateTimeZoneSupport::GROUP_ALL_WITH_BC === $timezoneGroup) {
            return self::timezoneIdentifiersAllWithBackwardCompat();
        }

        $ids = self::canonicalTimezoneIdentifiers();
        if (DateTimeZoneSupport::GROUP_ALL === $timezoneGroup) {
            return $ids;
        }

        $filtered = [];
        foreach ($ids as $id) {
            if (self::timezoneGroupAllowsIdentifier($id, $timezoneGroup)) {
                $filtered[] = $id;
            }
        }

        return $filtered;
    }

    public static function validateTimezoneId(string $timezone): string
    {
        $timezone = trim($timezone);
        if ('' === $timezone) {
            self::throwInvalidTimezone($timezone);
        }
        if (self::zoneinfoPath($timezone)) {
            return $timezone;
        }
        self::throwInvalidTimezone($timezone);
    }

    /** php-src ext/date/php_date.c — date_default_timezone_set() validation without throwing. */
    public static function timezoneIdIsValid(string $timezone): bool
    {
        return null !== self::zoneinfoPath(trim($timezone)) && '' !== trim($timezone);
    }

    /**
     * @return array{timestamp: int, microsecond: int}
     */
    public static function parseDateTime(string $time, string $tzName): array
    {
        $time = trim($time);
        if ('' === $time) {
            return self::readNow();
        }
        if ('now' === strtolower($time)) {
            return self::readNow();
        }
        if (str_starts_with($time, '@')) {
            $unix = substr($time, 1);
            if ('' === $unix || !ctype_digit($unix)) {
                self::throwMalformedDateTime($time);
            }

            return ['timestamp' => (int) $unix, 'microsecond' => 0];
        }
        if (1 === preg_match('/^\d+$/', $time)) {
            return ['timestamp' => (int) $time, 'microsecond' => 0];
        }
        if (1 === preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?)?$/',
            $time,
            $matches
        )) {
            $hour = isset($matches[4]) ? (int) $matches[4] : 0;
            $minute = isset($matches[5]) ? (int) $matches[5] : 0;
            $second = isset($matches[6]) ? (int) $matches[6] : 0;
            $microsecond = 0;
            if (isset($matches[7]) && '' !== $matches[7]) {
                $microsecond = (int) \str_pad(\substr($matches[7], 0, 6), 6, '0', STR_PAD_RIGHT);
            }

            return [
                'timestamp' => self::mktimeInTimezone(
                    (int) $matches[1],
                    (int) $matches[2],
                    (int) $matches[3],
                    $hour,
                    $minute,
                    $second,
                    $tzName
                ),
                'microsecond' => $microsecond,
            ];
        }

        self::throwMalformedDateTime($time);
    }

    /**
     * php-src PHP_FUNCTION(date_parse) — associative component breakdown (#6172).
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    public static function parseDate(string $date): array
    {
        $tzName = VmDate::defaultTimezoneGet();
        try {
            VmDateTimeNative::validateTimezoneId($tzName);

            return self::parseDateComponents(trim($date), $tzName);
        } catch (NativeDateMalformedStringException) {
            return self::failedParseResult([
                0 => 'The timezone could not be found in the database',
            ]);
        }
    }

    /**
     * php-src ext/standard/parsedate.c — preserve false for calendar fields absent from input (#11068).
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    private static function parseDateComponents(string $date, string $tzName): array
    {
        if ('' === $date) {
            return self::failedParseResult([0 => 'Empty string']);
        }
        if ('now' === strtolower($date)) {
            return self::parseResultFromComponents([
                'year' => false,
                'month' => false,
                'day' => false,
                'hour' => false,
                'minute' => false,
                'second' => false,
                'fraction' => false,
            ]);
        }
        if (str_starts_with($date, '@')) {
            $unix = substr($date, 1);
            if ('' === $unix || !ctype_digit($unix)) {
                self::throwMalformedDateTime($date);
            }

            return self::parseResultFromTimestamp((int) $unix, 0);
        }
        if (1 === preg_match('/^\d+$/', $date)) {
            return self::parseResultFromTimestamp((int) $date, 0);
        }
        if (1 === preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?)?$/',
            $date,
            $matches
        )) {
            $hasTime = isset($matches[4]);
            $fraction = false;
            if ($hasTime) {
                $fraction = 0.0;
                if (isset($matches[7]) && '' !== $matches[7]) {
                    $fraction = (float) ('0.'.\str_pad(\substr($matches[7], 0, 6), 6, '0', STR_PAD_RIGHT));
                }
            }

            return self::finalizeParsedDateComponents([
                'year' => (int) $matches[1],
                'month' => (int) $matches[2],
                'day' => (int) $matches[3],
                'hour' => $hasTime ? (int) $matches[4] : false,
                'minute' => $hasTime ? (int) $matches[5] : false,
                'second' => $hasTime ? (int) $matches[6] : false,
                'fraction' => $fraction,
            ], $tzName);
        }

        try {
            $parsed = self::parseDateTime($date, $tzName);

            return self::parseResultFromTimestamp($parsed['timestamp'], $parsed['microsecond']);
        } catch (NativeDateMalformedStringException) {
            return self::parseUnrecognizedDateString($date);
        }
    }

    /**
     * php-src PHP_FUNCTION(date_parse_from_format) — format-string breakdown (#6172).
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    public static function parseFromFormatComponents(string $format, string $time): array
    {
        $matched = self::matchFormatComponents($format, $time);
        if (false === $matched) {
            return self::failedParseResult([
                0 => 'A four digit year could not be found',
                3 => 'Not enough data available to satisfy format',
            ]);
        }
        $normalized = self::warnInvalidCalendarComponents($matched);
        $result = self::parseResultFromComponents($normalized['components']);
        if ([] !== $normalized['warnings']) {
            $result['warning_count'] = \count($normalized['warnings']);
            $result['warnings'] = $normalized['warnings'];
        }

        return $result;
    }

    /**
     * @return array{timestamp: int, microsecond: int}|false
     */
    public static function parseFromFormat(string $format, string $time, string $tzName): array|false
    {
        $matched = self::matchFormatComponents($format, $time);
        if (false === $matched) {
            return false;
        }
        $normalized = self::normalizeMatchedComponents($matched);
        $matched = $normalized['components'];
        $year = $matched['year'] ?? false;
        $month = $matched['month'] ?? false;
        $day = $matched['day'] ?? false;
        if (false === $year || false === $month || false === $day) {
            return false;
        }
        $hour = false === $matched['hour'] ? 0 : $matched['hour'];
        $minute = false === $matched['minute'] ? 0 : $matched['minute'];
        $second = false === $matched['second'] ? 0 : $matched['second'];
        $microsecond = (int) \round(($matched['fraction'] ?? 0.0) * 1_000_000);

        try {
            return [
                'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName),
                'microsecond' => $microsecond,
            ];
        } catch (NativeDateMalformedStringException) {
            return false;
        }
    }

    /**
     * php-src PHP_FUNCTION(strtotime) — natural-language / relative timestamps (#10742).
     */
    public static function strtotime(string $time, ?int $now = null): int|false
    {
        $time = trim($time);
        if ('' === $time) {
            return false;
        }
        $tzName = VmDate::defaultTimezoneGet();
        try {
            self::validateTimezoneId($tzName);
        } catch (\PHPCompiler\VM\NativeDateInvalidTimeZoneException) {
            return false;
        }
        $base = $now ?? self::readNow()['timestamp'];
        if (1 === preg_match(
            '/^next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $time,
            $matches
        )) {
            return self::nextWeekdayTimestamp(strtolower($matches[1]), $base, $tzName);
        }
        if (1 === preg_match(
            '/^[+-]?\d+\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)$/i',
            $time
        )) {
            try {
                return self::modifyRelative($base, $time, $tzName);
            } catch (NativeDateMalformedStringException) {
                return false;
            }
        }
        try {
            $parsed = self::parseDateTime($time, $tzName);

            return $parsed['timestamp'];
        } catch (NativeDateMalformedStringException) {
            return false;
        }
    }

    /** Zend DateTime serialize wire: `Y-m-d H:i:s.u` with six-digit fraction (#10710). */
    public static function formatZendDateWire(int $timestamp, int $microsecond, string $tzName): string
    {
        $date = self::format($timestamp, $microsecond, $tzName, 'Y-m-d H:i:s');
        $frac = \str_pad((string) $microsecond, 6, '0', STR_PAD_LEFT);

        return $date.'.'.$frac;
    }

    /**
     * @param array<int, string> $errors
     *
     * @return array{
     *   year: false,
     *   month: false,
     *   day: false,
     *   hour: false,
     *   minute: false,
     *   second: false,
     *   fraction: false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    private static function failedParseResult(array $errors): array
    {
        return [
            'year' => false,
            'month' => false,
            'day' => false,
            'hour' => false,
            'minute' => false,
            'second' => false,
            'fraction' => false,
            'warning_count' => 0,
            'warnings' => [],
            'error_count' => \count($errors),
            'errors' => $errors,
            'is_localtime' => false,
        ];
    }

    /**
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    private static function parseResultFromTimestamp(int $timestamp, int $microsecond): array
    {
        $tm = self::localtime($timestamp);
        if (null === $tm) {
            return self::failedParseResult([0 => 'The timezone could not be found in the database']);
        }

        return self::parseResultFromComponents([
            'year' => (int) $tm->tm_year + 1900,
            'month' => (int) $tm->tm_mon + 1,
            'day' => (int) $tm->tm_mday,
            'hour' => (int) $tm->tm_hour,
            'minute' => (int) $tm->tm_min,
            'second' => (int) $tm->tm_sec,
            'fraction' => $microsecond / 1_000_000,
        ]);
    }

    /**
     * @param array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false
     * } $components
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    private static function parseResultFromComponents(array $components): array
    {
        return [
            'year' => $components['year'],
            'month' => $components['month'],
            'day' => $components['day'],
            'hour' => $components['hour'],
            'minute' => $components['minute'],
            'second' => $components['second'],
            'fraction' => $components['fraction'],
            'warning_count' => 0,
            'warnings' => [],
            'error_count' => 0,
            'errors' => [],
            'is_localtime' => false,
        ];
    }

    /**
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float
     * }|false
     */
    private static function matchFormatComponents(string $format, string $time): array|false
    {
        $bangReset = false;
        if (\str_starts_with($format, '!')) {
            $bangReset = true;
            $format = \substr($format, 1);
        }
        $pos = 0;
        $timeLen = \strlen($time);
        $components = [
            'year' => false,
            'month' => false,
            'day' => false,
            'hour' => false,
            'minute' => false,
            'second' => false,
            'fraction' => 0.0,
        ];
        $formatLen = \strlen($format);
        for ($i = 0; $i < $formatLen; ++$i) {
            $fc = $format[$i];
            if ('\\' === $fc) {
                if ($i + 1 >= $formatLen) {
                    return false;
                }
                $literal = $format[++$i];
                if ($pos >= $timeLen || $time[$pos] !== $literal) {
                    return false;
                }
                ++$pos;

                continue;
            }
            switch ($fc) {
                case 'Y':
                    $digits = self::readDigits($time, $pos, 4, 4);
                    if (false === $digits) {
                        return false;
                    }
                    $components['year'] = (int) $digits;

                    break;
                case 'y':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $yy = (int) $digits;
                    $components['year'] = $yy >= 69 ? 1900 + $yy : 2000 + $yy;

                    break;
                case 'm':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['month'] = (int) $digits;

                    break;
                case 'n':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['month'] = (int) $digits;

                    break;
                case 'd':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['day'] = (int) $digits;

                    break;
                case 'j':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['day'] = (int) $digits;

                    break;
                case 'H':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['hour'] = (int) $digits;

                    break;
                case 'G':
                    $digits = self::readDigits($time, $pos, 1, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['hour'] = (int) $digits;

                    break;
                case 'i':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['minute'] = (int) $digits;

                    break;
                case 's':
                    $digits = self::readDigits($time, $pos, 2, 2);
                    if (false === $digits) {
                        return false;
                    }
                    $components['second'] = (int) $digits;

                    break;
                case 'u':
                    $digits = self::readDigits($time, $pos, 1, 6);
                    if (false === $digits) {
                        return false;
                    }
                    $components['fraction'] = (int) \str_pad($digits, 6, '0', STR_PAD_RIGHT) / 1_000_000;

                    break;
                case 'U':
                    $digits = self::readDigits($time, $pos, 1, null);
                    if (false === $digits) {
                        return false;
                    }
                    $tm = self::localtime((int) $digits);
                    if (null === $tm) {
                        return false;
                    }
                    $components['year'] = (int) $tm->tm_year + 1900;
                    $components['month'] = (int) $tm->tm_mon + 1;
                    $components['day'] = (int) $tm->tm_mday;
                    $components['hour'] = (int) $tm->tm_hour;
                    $components['minute'] = (int) $tm->tm_min;
                    $components['second'] = (int) $tm->tm_sec;

                    break;
                default:
                    if ($pos >= $timeLen || $time[$pos] !== $fc) {
                        return false;
                    }
                    ++$pos;
            }
        }
        if ($pos !== $timeLen) {
            return false;
        }
        if ($bangReset) {
            foreach ([
                'year' => 1970,
                'month' => 1,
                'day' => 1,
                'hour' => 0,
                'minute' => 0,
                'second' => 0,
            ] as $key => $default) {
                if (false === $components[$key]) {
                    $components[$key] = $default;
                }
            }
        }

        return $components;
    }

    /**
     * @param array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float
     * } $components
     *
     * @return array{components: array<string, int|false|float>, warnings: array<int, string>}
     */
    private static function warnInvalidCalendarComponents(array $components): array
    {
        $warnings = [];
        $year = $components['year'];
        $month = $components['month'];
        $day = $components['day'];
        if (false !== $year && false !== $month && false !== $day
            && !self::isValidCalendarDate($year, $month, $day)) {
            $warnings[10] = 'The parsed date was invalid';
        }

        return ['components' => $components, 'warnings' => $warnings];
    }

    /**
     * @param array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float
     * } $components
     *
     * @return array{components: array<string, int|false|float>, warnings: array<int, string>}
     */
    private static function normalizeMatchedComponents(array $components): array
    {
        $warnings = [];
        $year = $components['year'];
        $month = $components['month'];
        $day = $components['day'];
        if (false === $year || false === $month || false === $day) {
            return ['components' => $components, 'warnings' => $warnings];
        }
        $invalid = false;
        while ($month > 12) {
            $month -= 12;
            ++$year;
            $invalid = true;
        }
        while ($month < 1) {
            $month += 12;
            --$year;
            $invalid = true;
        }
        while ($day > self::daysInMonth($year, $month)) {
            $day -= self::daysInMonth($year, $month);
            ++$month;
            if ($month > 12) {
                $month = 1;
                ++$year;
            }
            $invalid = true;
        }
        if ($invalid) {
            $warnings[10] = 'The parsed date was invalid';
        }
        $components['year'] = $year;
        $components['month'] = $month;
        $components['day'] = $day;

        return ['components' => $components, 'warnings' => $warnings];
    }

    /**
     * php-src ext/standard/parsedate.c — calendar overflow + invalid-day handling for date_parse() (#11225).
     *
     * @param array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false
     * } $components
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    private static function finalizeParsedDateComponents(array $components, string $tzName): array
    {
        $year = $components['year'];
        $month = $components['month'];
        $day = $components['day'];
        if (!\is_int($year) || !\is_int($month) || !\is_int($day)) {
            return self::parseResultFromComponents($components);
        }
        if ($month > 12) {
            $components['month'] = 1;
            $components['day'] = 1;
            $result = self::parseResultFromComponents($components);
            $result['error_count'] = 1;
            $result['errors'] = [6 => 'Unexpected character'];
            $result['is_localtime'] = true;

            return $result;
        }
        if ($day > 31) {
            $hour = false === $components['hour'] ? 0 : $components['hour'];
            $minute = false === $components['minute'] ? 0 : $components['minute'];
            $second = false === $components['second'] ? 0 : $components['second'];
            $microsecond = 0;
            if (false !== $components['fraction']) {
                $microsecond = (int) \round($components['fraction'] * 1_000_000);
            }
            try {
                $timestamp = self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName);
                $rolled = self::parseResultFromTimestamp($timestamp, $microsecond);
                $result = self::parseResultFromComponents([
                    'year' => $rolled['year'],
                    'month' => $rolled['month'],
                    'day' => $rolled['day'],
                    'hour' => $components['hour'],
                    'minute' => $components['minute'],
                    'second' => $components['second'],
                    'fraction' => $components['fraction'],
                ]);
                $result['error_count'] = 1;
                $result['errors'] = [9 => 'Unexpected character'];
                $result['is_localtime'] = true;

                return $result;
            } catch (NativeDateMalformedStringException) {
                return self::parseResultFromComponents($components);
            }
        }
        if (!self::isValidCalendarDate($year, $month, $day)) {
            $result = self::parseResultFromComponents($components);
            $result['warning_count'] = 1;
            $result['warnings'] = [11 => 'The parsed date was invalid'];

            return $result;
        }

        return self::parseResultFromComponents($components);
    }

    /**
     * php-src timelib parse errors for strings that do not match structured patterns (#11225).
     *
     * @return array{
     *   year: int|false,
     *   month: int|false,
     *   day: int|false,
     *   hour: int|false,
     *   minute: int|false,
     *   second: int|false,
     *   fraction: float|false,
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>,
     *   is_localtime: bool
     * }
     */
    private static function parseUnrecognizedDateString(string $date): array
    {
        $errors = [];
        $warnings = [];
        $len = \strlen($date);
        if (preg_match('/^[A-Za-z]+/', $date, $firstWord)) {
            try {
                self::validateTimezoneId($firstWord[0]);
            } catch (\PHPCompiler\VM\NativeDateInvalidTimeZoneException) {
                $errors[0] = 'The timezone could not be found in the database';
            }
        }
        for ($pos = 0; $pos < $len; ++$pos) {
            if ('-' === $date[$pos] && !\preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                $errors[$pos] = 'Unexpected character';
            }
        }
        if (
            \preg_match_all('/[A-Za-z]+/', $date) >= 2
            && \substr_count($date, '-') >= 2
        ) {
            $errors[6] = 'Double timezone specification';
            $warnings[4] = 'Double timezone specification';
        }
        if ([] === $errors) {
            $errors[0] = 'The timezone could not be found in the database';
        }
        $result = self::failedParseResult($errors);
        if ([] !== $warnings) {
            $result['warning_count'] = \count($warnings);
            $result['warnings'] = $warnings;
        }
        $result['is_localtime'] = true;

        return $result;
    }

    private static function isValidCalendarDate(int $year, int $month, int $day): bool
    {
        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }

        return $day <= self::daysInMonth($year, $month);
    }

    private static function nextWeekdayTimestamp(string $weekday, int $base, string $tzName): int|false
    {
        $tm = self::localtime($base);
        if (null === $tm) {
            return false;
        }
        $target = match ($weekday) {
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            default => -1,
        };
        if ($target < 0) {
            return false;
        }
        $current = (int) $tm->tm_wday;
        $days = ($target - $current + 7) % 7;
        if (0 === $days) {
            $days = 7;
        }
        try {
            return self::modifyRelative($base, '+'.$days.' day', $tzName);
        } catch (NativeDateMalformedStringException) {
            return false;
        }
    }

    private static function readDigits(string $time, int &$pos, int $min, ?int $max): string|false
    {
        $timeLen = \strlen($time);
        $start = $pos;
        while ($pos < $timeLen && \ctype_digit($time[$pos])) {
            ++$pos;
            if (null !== $max && $pos - $start >= $max) {
                break;
            }
        }
        $len = $pos - $start;
        if ($len < $min) {
            $pos = $start;

            return false;
        }

        return \substr($time, $start, $len);
    }

    /**
     * php-src date_modify() / timelib_strtotime relative branch — common signed units only (#6132).
     */
    public static function modifyRelative(int $timestamp, string $modifier, string $tzName): int
    {
        $modifier = trim($modifier);
        if ('' === $modifier) {
            self::throwModifyMalformed($modifier);
        }

        return self::withTimezone($tzName, static function () use ($timestamp, $modifier, $tzName): int {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                self::throwModifyMalformed($modifier);
            }
            $year = (int) $tm->tm_year + 1900;
            $month = (int) $tm->tm_mon + 1;
            $day = (int) $tm->tm_mday;
            $hour = (int) $tm->tm_hour;
            $minute = (int) $tm->tm_min;
            $second = (int) $tm->tm_sec;
            $delta = self::parseSignedRelativeDelta($modifier);
            switch ($delta['unit']) {
                case 'second':
                    $second += $delta['amount'];
                    break;
                case 'minute':
                    $minute += $delta['amount'];
                    break;
                case 'hour':
                    $hour += $delta['amount'];
                    break;
                case 'day':
                    $day += $delta['amount'];
                    break;
                case 'week':
                    $day += 7 * $delta['amount'];
                    break;
                case 'month':
                    $month += $delta['amount'];
                    break;
                case 'year':
                    $year += $delta['amount'];
                    break;
                default:
                    self::throwModifyMalformed($modifier);
            }

            return self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName);
        });
    }

    public static function format(int $timestamp, int $microsecond, string $tzName, string $format): string
    {
        return self::withTimezone($tzName, static function () use ($timestamp, $microsecond, $format, $tzName): string {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                return '';
            }
            $offset = self::timezoneOffsetSeconds($tzName, $timestamp);

            return VmDate::formatDateTimeFromTm($format, $timestamp, $microsecond, $tm, $offset, $tzName);
        });
    }

    public static function timezoneOffsetSeconds(string $tzName, int $timestamp): int
    {
        return self::withTimezone($tzName, static function () use ($timestamp): int {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                return 0;
            }
            $ffi = self::ffi();
            if (null === $ffi) {
                return 0;
            }
            $asUtc = (int) $ffi->timegm(\FFI::addr($tm));

            return $asUtc - $timestamp;
        });
    }

    /**
     * php-src zim_DateTimeZone_getLocation / timezone_location_get (#7131, #6041).
     *
     * @return array{country_code: string, latitude: float, longitude: float, comments: string}|false
     */
    public static function timezoneLocation(string $tzName): array|false
    {
        if (!self::zoneinfoPath($tzName)) {
            return false;
        }

        $entry = self::zoneTabEntryForId($tzName);
        if (null === $entry) {
            return [
                'country_code' => '??',
                'latitude' => 0.0,
                'longitude' => 0.0,
                'comments' => '?',
            ];
        }

        return [
            'country_code' => $entry['country'],
            'latitude' => $entry['latitude'],
            'longitude' => $entry['longitude'],
            'comments' => $entry['comments'],
        ];
    }

    /**
     * php-src timezone_transitions_get / zim_DateTimeZone_getTransitions (#6041).
     *
     * @return list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}>|false
     */
    public static function timezoneTransitions(string $tzName, int $begin, int $end): array|false
    {
        if (!self::zoneinfoPath($tzName)) {
            return false;
        }
        if ($begin > $end) {
            return false;
        }

        $transitions = [self::buildTransitionRecord($tzName, $begin)];
        $state = self::transitionState($tzName, $begin);
        $cursor = $begin;
        $step = 86400;

        while ($cursor < $end) {
            $nextProbe = min($cursor + $step, $end);
            $nextState = self::transitionState($tzName, $nextProbe);
            if ($nextState['offset'] !== $state['offset'] || $nextState['isdst'] !== $state['isdst']) {
                $lo = $cursor;
                $hi = $nextProbe;
                while ($hi - $lo > 1) {
                    $mid = intdiv($lo + $hi, 2);
                    $midState = self::transitionState($tzName, $mid);
                    if ($midState['offset'] !== $state['offset'] || $midState['isdst'] !== $state['isdst']) {
                        $hi = $mid;
                    } else {
                        $lo = $mid;
                    }
                }
                if ($hi !== $begin && ($transitions[\count($transitions) - 1]['ts'] ?? null) !== $hi) {
                    $transitions[] = self::buildTransitionRecord($tzName, $hi);
                }
                $state = self::transitionState($tzName, $hi);
                $cursor = $hi;
            } else {
                $cursor = $nextProbe;
            }
        }

        return $transitions;
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
        return self::withTimezone($tzName, static function () use (
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        ): int {
            $ffi = self::ffi();
            if (null === $ffi) {
                self::throwMalformedDateTime("{$year}-{$month}-{$day}");
            }
            $tm = $ffi->new('struct tm');
            $tm->tm_sec = $second;
            $tm->tm_min = $minute;
            $tm->tm_hour = $hour;
            $tm->tm_mday = $day;
            $tm->tm_mon = $month - 1;
            $tm->tm_year = $year - 1900;
            $tm->tm_isdst = -1;
            $result = (int) $ffi->mktime(\FFI::addr($tm));
            if (-1 === $result) {
                self::throwMalformedDateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}");
            }

            return $result;
        });
    }

    /**
     * @return array{timestamp: int, microsecond: int}
     */
    private static function readNow(): array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return ['timestamp' => 0, 'microsecond' => 0];
        }
        $tv = $ffi->new('struct timeval');
        if (0 !== (int) $ffi->gettimeofday(\FFI::addr($tv), null)) {
            return ['timestamp' => 0, 'microsecond' => 0];
        }

        return ['timestamp' => (int) $tv->tv_sec, 'microsecond' => (int) $tv->tv_usec];
    }

    private static function localtime(int $timestamp): ?\FFI\CData
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $ts = $ffi->new('time_t');
        $ts->cdata = $timestamp;
        $buf = $ffi->new('struct tm');

        return null === $ffi->localtime_r(\FFI::addr($ts), \FFI::addr($buf)) ? null : $buf;
    }

    private static function formatOffset(int $offsetSeconds): string
    {
        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $abs = \abs($offsetSeconds);
        $hours = (int) \floor($abs / 3600);
        $minutes = (int) \floor(($abs % 3600) / 60);

        return $sign.self::padInt($hours, 2).':'.self::padInt($minutes, 2);
    }

    private static function padInt(int $value, int $width): string
    {
        $negative = $value < 0;
        $value = \abs($value);
        $s = (string) $value;
        if (\strlen($s) >= $width) {
            return ($negative ? '-' : '').$s;
        }

        return ($negative ? '-' : '').\str_repeat('0', $width - \strlen($s)).$s;
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    private static function parseZoneTabCoordinates(string $coords): array
    {
        if (!preg_match('/^([+-])(\d{2})(\d{2})([+-])(\d{3})(\d{2})$/', $coords, $matches)) {
            return ['latitude' => 0.0, 'longitude' => 0.0];
        }
        $latSign = '+' === $matches[1] ? 1 : -1;
        $lat = $latSign * ((int) $matches[2] + ((int) $matches[3]) / 60);
        $lonSign = '+' === $matches[4] ? 1 : -1;
        $lon = $lonSign * ((int) $matches[5] + ((int) $matches[6]) / 60);

        return ['latitude' => $lat, 'longitude' => $lon];
    }

    /** @return list<string> */
    private static function canonicalTimezoneIdentifiers(): array
    {
        $ids = [];
        foreach (self::zoneTabEntries() as $entry) {
            $ids[] = $entry['id'];
        }
        $ids[] = 'UTC';
        \sort($ids);

        return $ids;
    }

    /**
     * @return array{country: string, latitude: float, longitude: float, comments: string}|null
     */
    /**
     * Zone.tab rows for JIT/AOT location lowering (#6041 phase 2).
     *
     * @return list<array{country: string, id: string, latitude: float, longitude: float, comments: string}>
     */
    public static function exportZoneTabEntries(): array
    {
        return self::zoneTabEntries();
    }

    /**
     * DST transitions for JIT/AOT lowering (#6041 phase 2).
     *
     * @return list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}>|false
     */
    public static function exportTimezoneTransitions(string $tzName, int $begin, int $end): array|false
    {
        return self::timezoneTransitions($tzName, $begin, $end);
    }

    private static function zoneTabEntryForId(string $tzName): ?array
    {
        foreach (self::zoneTabEntries() as $entry) {
            if (0 === strcasecmp($entry['id'], $tzName)) {
                return [
                    'country' => $entry['country'],
                    'latitude' => $entry['latitude'],
                    'longitude' => $entry['longitude'],
                    'comments' => $entry['comments'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{offset: int, isdst: bool}
     */
    private static function transitionState(string $tzName, int $timestamp): array
    {
        return self::withTimezone($tzName, static function () use ($timestamp): array {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                return ['offset' => 0, 'isdst' => false];
            }
            $ffi = self::ffi();
            $isdst = 1 === (int) $tm->tm_isdst;
            if (null === $ffi) {
                return ['offset' => 0, 'isdst' => $isdst];
            }
            $asUtc = (int) $ffi->timegm(\FFI::addr($tm));

            return ['offset' => $asUtc - $timestamp, 'isdst' => $isdst];
        });
    }

    /**
     * @return array{ts: int, time: string, offset: int, isdst: bool, abbr: string}
     */
    private static function buildTransitionRecord(string $tzName, int $timestamp): array
    {
        $state = self::transitionState($tzName, $timestamp);

        return [
            'ts' => $timestamp,
            'time' => self::format($timestamp, 0, $tzName, 'c'),
            'offset' => $state['offset'],
            'isdst' => $state['isdst'],
            'abbr' => self::timezoneAbbreviation($tzName, $timestamp),
        ];
    }

    private static function timezoneAbbreviation(string $tzName, int $timestamp): string
    {
        return self::withTimezone($tzName, static function () use ($timestamp): string {
            $ffi = self::ffi();
            $tm = self::localtime($timestamp);
            if (null === $ffi || null === $tm) {
                return '';
            }
            $buf = $ffi->new('char[16]');
            $len = (int) $ffi->strftime(\FFI::addr($buf[0]), 16, '%Z', \FFI::addr($tm));
            if ($len <= 0) {
                return '';
            }

            return \rtrim(\FFI::string($buf), "\0");
        });
    }

    /** @return list<array{country: string, id: string, latitude: float, longitude: float, comments: string}> */
    private static function zoneTabEntries(): array
    {
        if (null !== self::$zoneTabEntries) {
            return self::$zoneTabEntries;
        }
        self::$zoneTabEntries = [];
        $path = self::ZONEINFO_ROOT.'/zone.tab';
        if (!\is_file($path)) {
            return self::$zoneTabEntries;
        }
        $lines = VmFs::file(
            $path,
            StdlibConstants::FILE_IGNORE_NEW_LINES | StdlibConstants::FILE_SKIP_EMPTY_LINES
        );
        if (false === $lines) {
            return self::$zoneTabEntries;
        }
        foreach ($lines as $line) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $parts = \preg_split('/\s+/', $line, 4);
            if (!\is_array($parts) || \count($parts) < 3) {
                continue;
            }
            $coords = self::parseZoneTabCoordinates($parts[1]);
            self::$zoneTabEntries[] = [
                'country' => $parts[0],
                'id' => $parts[2],
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
                'comments' => $parts[3] ?? '',
            ];
        }

        return self::$zoneTabEntries;
    }

    /** @return list<string> */
    private static function timezoneIdentifiersAllWithBackwardCompat(): array
    {
        $ids = self::canonicalTimezoneIdentifiers();
        $known = \array_fill_keys($ids, true);
        if (!\is_dir(self::ZONEINFO_ROOT)) {
            return $ids;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ZONEINFO_ROOT, \FilesystemIterator::SKIP_DOTS)
        );
        $rootLen = \strlen(self::ZONEINFO_ROOT) + 1;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '.') && !str_ends_with($path, 'posixrules')) {
                $base = \basename($path);
                if (str_contains($base, '.')) {
                    continue;
                }
            }
            $id = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($path, $rootLen));
            if (isset($known[$id])) {
                continue;
            }
            if (!self::timezoneGroupAllowsIdentifier($id, DateTimeZoneSupport::GROUP_ALL)) {
                continue;
            }
            if (\is_link($path) || !str_contains($id, '/')) {
                $ids[] = $id;
                $known[$id] = true;
            }
        }
        \sort($ids);

        return $ids;
    }

    private static function timezoneGroupAllowsIdentifier(string $id, int $timezoneGroup): bool
    {
        if ((DateTimeZoneSupport::GROUP_AFRICA & $timezoneGroup) && 0 === strncasecmp($id, 'Africa/', 7)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_AMERICA & $timezoneGroup) && 0 === strncasecmp($id, 'America/', 8)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_ANTARCTICA & $timezoneGroup) && 0 === strncasecmp($id, 'Antarctica/', 11)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_ARCTIC & $timezoneGroup) && 0 === strncasecmp($id, 'Arctic/', 7)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_ASIA & $timezoneGroup) && 0 === strncasecmp($id, 'Asia/', 5)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_ATLANTIC & $timezoneGroup) && 0 === strncasecmp($id, 'Atlantic/', 9)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_AUSTRALIA & $timezoneGroup) && 0 === strncasecmp($id, 'Australia/', 10)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_EUROPE & $timezoneGroup) && 0 === strncasecmp($id, 'Europe/', 7)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_INDIAN & $timezoneGroup) && 0 === strncasecmp($id, 'Indian/', 7)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_PACIFIC & $timezoneGroup) && 0 === strncasecmp($id, 'Pacific/', 8)) {
            return true;
        }
        if ((DateTimeZoneSupport::GROUP_UTC & $timezoneGroup) && 0 === strncasecmp($id, 'UTC', 3)) {
            return true;
        }

        return false;
    }

    private static function zoneinfoPath(string $timezone): ?string
    {
        if (str_contains($timezone, "\0") || str_starts_with($timezone, '/') || str_contains($timezone, '..')) {
            return null;
        }
        $path = self::ZONEINFO_ROOT.'/'.str_replace('/', \DIRECTORY_SEPARATOR, $timezone);
        if (\is_file($path)) {
            return $path;
        }
        foreach (self::zoneIdentifiers() as $identifier) {
            if (0 === strcasecmp($identifier, $timezone)) {
                $candidate = self::ZONEINFO_ROOT.'/'.str_replace('/', \DIRECTORY_SEPARATOR, $identifier);
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function zoneIdentifiers(): array
    {
        if (null !== self::$zoneIdentifiers) {
            return self::$zoneIdentifiers;
        }
        self::$zoneIdentifiers = [];
        if (!\is_dir(self::ZONEINFO_ROOT)) {
            return self::$zoneIdentifiers;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::ZONEINFO_ROOT, \FilesystemIterator::SKIP_DOTS)
        );
        $rootLen = \strlen(self::ZONEINFO_ROOT) + 1;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '.') && !str_ends_with($path, 'posixrules')) {
                $base = \basename($path);
                if (str_contains($base, '.')) {
                    continue;
                }
            }
            self::$zoneIdentifiers[] = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($path, $rootLen));
        }

        return self::$zoneIdentifiers;
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private static function withTimezone(string $tzName, callable $fn): mixed
    {
        $ffi = self::ffi();
        if (0 === self::$withTimezoneDepth) {
            self::$withTimezoneSavedVmEnvTz = VmEnv::getenv('TZ');
        }
        ++self::$withTimezoneDepth;
        VmEnv::putenv('TZ='.$tzName);
        if (null !== $ffi && self::$activeLibcTimezone !== $tzName) {
            $ffi->setenv('TZ', $tzName, 1);
            $ffi->tzset();
            self::$activeLibcTimezone = $tzName;
        }
        try {
            return $fn();
        } finally {
            --self::$withTimezoneDepth;
            if (0 === self::$withTimezoneDepth) {
                if (false === self::$withTimezoneSavedVmEnvTz || '' === self::$withTimezoneSavedVmEnvTz) {
                    VmEnv::putenv('TZ');
                } else {
                    VmEnv::putenv('TZ='.self::$withTimezoneSavedVmEnvTz);
                }
            }
        }
    }

    private static function throwInvalidTimezone(string $timezone): never
    {
        throw new \PHPCompiler\VM\NativeDateInvalidTimeZoneException(
            'DateTimeZone::__construct(): Unknown or bad timezone ('.$timezone.')'
        );
    }

    private static function throwMalformedDateTime(string $time): never
    {
        throw new \PHPCompiler\VM\NativeDateMalformedStringException(
            'Failed to parse time string ('.$time.') at position 0 ('.$time[0].'): The timezone could not be found in the database'
        );
    }

    private static function throwModifyMalformed(string $modifier): never
    {
        $pos = '' !== $modifier ? $modifier[0] : '';
        throw new \PHPCompiler\VM\NativeDateMalformedStringException(
            'Failed to parse time string ('.$modifier.') at position 0 ('.$pos.'): Unexpected character'
        );
    }

    /**
     * Apply DateInterval fields to a timestamp (php-src php_date_add / php_date_sub, #4604).
     *
     * @param array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int} $state
     *
     * @return array{timestamp: int, microsecond: int}
     */
    public static function applyIntervalState(
        int $timestamp,
        int $microsecond,
        array $state,
        string $tzName,
        bool $add
    ): array {
        $invert = 0 !== $state['invert'];
        $subtract = $add ? $invert : !$invert;
        $sign = $subtract ? -1 : 1;

        return self::withTimezone($tzName, static function () use (
            $timestamp,
            $microsecond,
            $state,
            $tzName,
            $sign
        ): array {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                throw new \LogicException('Invalid timestamp for DateInterval application');
            }
            $year = (int) $tm->tm_year + 1900;
            $month = (int) $tm->tm_mon + 1;
            $day = (int) $tm->tm_mday;
            $hour = (int) $tm->tm_hour;
            $minute = (int) $tm->tm_min;
            $second = (int) $tm->tm_sec;

            $year += $sign * $state['y'];
            $month += $sign * $state['m'];
            $day += $sign * $state['d'];
            $hour += $sign * $state['h'];
            $minute += $sign * $state['i'];
            $second += $sign * $state['s'];

            $newTs = self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName);
            $newMicro = $microsecond + (int) \round($sign * $state['f'] * 1_000_000);
            if ($newMicro >= 1_000_000) {
                $newTs += intdiv($newMicro, 1_000_000);
                $newMicro %= 1_000_000;
            } elseif ($newMicro < 0) {
                --$newTs;
                $newMicro += 1_000_000;
            }

            return ['timestamp' => $newTs, 'microsecond' => $newMicro];
        });
    }

    /**
     * Calendar diff between two timestamps (php-src php_date_diff v1, #4604).
     *
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: int}
     */
    public static function diffTimestamps(
        int $baseTs,
        int $targetTs,
        string $tzName,
        bool $absolute = false
    ): array {
        $invert = $targetTs < $baseTs ? 1 : 0;
        $earlier = $invert ? $targetTs : $baseTs;
        $later = $invert ? $baseTs : $targetTs;
        if ($absolute) {
            $invert = 0;
        }
        $days = (int) \floor(\abs($targetTs - $baseTs) / 86_400);

        return self::withTimezone($tzName, static function () use ($earlier, $later, $invert, $days): array {
            $tm1 = self::localtime($earlier);
            $tm2 = self::localtime($later);
            if (null === $tm1 || null === $tm2) {
                throw new \LogicException('Invalid timestamp for date_diff()');
            }

            $y1 = (int) $tm1->tm_year + 1900;
            $m1 = (int) $tm1->tm_mon + 1;
            $d1 = (int) $tm1->tm_mday;
            $h1 = (int) $tm1->tm_hour;
            $i1 = (int) $tm1->tm_min;
            $s1 = (int) $tm1->tm_sec;

            $y2 = (int) $tm2->tm_year + 1900;
            $m2 = (int) $tm2->tm_mon + 1;
            $d2 = (int) $tm2->tm_mday;
            $h2 = (int) $tm2->tm_hour;
            $i2 = (int) $tm2->tm_min;
            $s2 = (int) $tm2->tm_sec;

            $s = $s2 - $s1;
            $i = $i2 - $i1;
            $h = $h2 - $h1;
            $d = $d2 - $d1;
            $m = $m2 - $m1;
            $y = $y2 - $y1;

            if ($s < 0) {
                $s += 60;
                --$i;
            }
            if ($i < 0) {
                $i += 60;
                --$h;
            }
            if ($h < 0) {
                $h += 24;
                --$d;
            }
            if ($d < 0) {
                $prevMonth = $m2 - 1;
                $prevYear = $y2;
                if ($prevMonth < 1) {
                    $prevMonth = 12;
                    --$prevYear;
                }
                $d += self::daysInMonth($prevYear, $prevMonth);
                --$m;
            }
            if ($m < 0) {
                $m += 12;
                --$y;
            }

            return [
                'y' => $y,
                'm' => $m,
                'd' => $d,
                'h' => $h,
                'i' => $i,
                's' => $s,
                'f' => 0.0,
                'invert' => $invert,
                'days' => $days,
            ];
        });
    }

    private static function daysInMonth(int $year, int $month): int
    {
        static $mdays = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if (2 === $month) {
            $leap = (0 === $year % 4) && (0 !== $year % 100 || 0 === $year % 400);

            return $leap ? 29 : 28;
        }

        return $mdays[$month - 1];
    }

    /**
     * @return array{amount: int, unit: string}
     */
    private static function parseSignedRelativeDelta(string $modifier): array
    {
        if (!preg_match(
            '/^([+-])\s*(\d+)\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)$/i',
            $modifier,
            $matches
        )) {
            self::throwModifyMalformed($modifier);
        }
        $sign = '-' === $matches[1] ? -1 : 1;
        $amount = $sign * (int) $matches[2];
        $unit = strtolower($matches[3]);
        if (str_ends_with($unit, 's')) {
            $unit = substr($unit, 0, -1);
        }

        return ['amount' => $amount, 'unit' => $unit];
    }

    private static function ffi(): ?\FFI
    {
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            return null;
        }
        $cdef = <<<'CDEF'
typedef long time_t;
struct timeval {
    time_t tv_sec;
    long tv_usec;
};
struct tm {
    int tm_sec;
    int tm_min;
    int tm_hour;
    int tm_mday;
    int tm_mon;
    int tm_year;
    int tm_wday;
    int tm_yday;
    int tm_isdst;
};
int setenv(const char *name, const char *value, int overwrite);
void tzset(void);
time_t mktime(struct tm *tm);
time_t timegm(struct tm *tm);
struct tm *localtime_r(const time_t *timep, struct tm *result);
int gettimeofday(struct timeval *tv, void *tz);
size_t strftime(char *s, size_t max, const char *format, const struct tm *tm);
CDEF;

        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
