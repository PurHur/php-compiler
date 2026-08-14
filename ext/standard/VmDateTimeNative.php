<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\DateTimeZoneSupport;
use PHPCompiler\VM\NativeDateMalformedStringException;
use PHPCompiler\VM\Variable;

/**
 * Native DateTime/DateTimeZone semantics without host Zend \\DateTime (issue #6164).
 * TZ switching via {@see VmEnv} — no host Zend env builtins (#8086).
 * zone.tab reads via {@see VmFs::file()} / {@see VmFsReadNative} — no host \\file() (#8529).
 *
 * php-src ref: ext/date/php_datetime.c, ext/date/lib/timelib.c — parsing, formatting, offsets.
 * Time libc via {@see VmDatePure} host wrappers (#13765, #13857); timezone IDs validated via zoneinfo files.
 */
final class VmDateTimeNative
{
    private const ZONEINFO_ROOT = '/usr/share/zoneinfo';

    /** Cached Olson/tzdata version from zoneinfo (timezone_version_get, #29386). */
    private static ?string $timezoneDbVersion = null;

    /** Day-scan fallback only for narrow windows; full-range uses TZif (#11069). */
    private const TRANSITION_SCAN_MAX_SPAN = 86400 * 366 * 10;

    private const FORMAT_OUT_BYTES = 256;

    /** @var list<string>|null */
    private static ?array $zoneIdentifiers = null;

    /** @var list<array{country: string, id: string}>|null */
    private static ?array $zoneTabEntries = null;

    private static ?Variable $timezoneAbbreviationsCache = null;

    private static int $withTimezoneDepth = 0;

    /** @var string|false */
    private static string|false $withTimezoneSavedVmEnvTz = false;

    private static ?string $withTimezoneSavedHostTz = null;

    /** Set when createFromFormat format is satisfied but time has trailing junk (#14173, #16196). */
    private static bool $createFromFormatTrailingData = false;

    /**
     * timezone_version_get() — IANA tzdata version for the zoneinfo tree we actually use (#29386).
     *
     * php-src returns `DATE_TIMEZONEDB->version` (bundled timezonedb) or `0.system` under
     * `USE_SYSTEM_TZDATA`. This runtime validates zones via `/usr/share/zoneinfo`, so prefer
     * the `# version …` line from `tzdata.zi` (or `+VERSION`) over the opaque sentinel.
     */
    public static function timezoneDbVersion(): string
    {
        if (null !== self::$timezoneDbVersion) {
            return self::$timezoneDbVersion;
        }
        $parsed = self::readTimezoneDbVersionFromZoneinfo();
        self::$timezoneDbVersion = null !== $parsed && '' !== $parsed ? $parsed : '0.system';

        return self::$timezoneDbVersion;
    }

    /**
     * @return non-empty-string|null
     */
    private static function readTimezoneDbVersionFromZoneinfo(): ?string
    {
        $ziPath = self::ZONEINFO_ROOT.'/tzdata.zi';
        if (\is_file($ziPath)) {
            // Header only — full tzdata.zi is ~100KiB+; version is on line 1 (#29386).
            $head = VmFs::fileGetContents($ziPath, false, null, 0, 512);
            if (\is_string($head) && 1 === \preg_match('/^#\s*version\s+(\S+)/m', $head, $match)) {
                $version = $match[1];
                if ('' !== $version) {
                    return $version;
                }
            }
        }

        $plusPath = self::ZONEINFO_ROOT.'/+VERSION';
        if (\is_file($plusPath)) {
            $raw = VmFs::fileGetContents($plusPath);
            if (\is_string($raw) && '' !== $raw) {
                $line = \trim(\explode("\n", $raw, 2)[0]);
                if ('' !== $line && !\str_starts_with($line, '#')) {
                    return $line;
                }
                if (1 === \preg_match('/version\s+(\S+)/i', $line, $match) && '' !== $match[1]) {
                    return $match[1];
                }
            }
        }

        return null;
    }

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

    /**
     * timezone_abbreviations_list() / DateTimeZone::listAbbreviations() — timelib precompiled map.
     *
     * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_abbreviations_list)
     */
    public static function timezoneAbbreviationsListVariable(): Variable
    {
        if (null === self::$timezoneAbbreviationsCache) {
            /** @var array<string, list<array{dst: bool, offset: int, timezone_id: ?string}>> $data */
            $data = require __DIR__.'/TimezoneAbbreviationsData.php';
            self::$timezoneAbbreviationsCache = VmJson::import($data);
        }
        $copy = new Variable();
        $copy->copyFrom(self::$timezoneAbbreviationsCache);

        return $copy;
    }

    /**
     * timezone_name_from_abbr() — timelib abbr_search + fallbackmap (ext/date/php_date.c, #10957).
     *
     * @return string|false
     */
    public static function timezoneNameFromAbbr(string $abbr, int $gmtoffset = -1, int $isdst = -1): string|false
    {
        if (0 === strcasecmp($abbr, 'utc') || 0 === strcasecmp($abbr, 'gmt')) {
            return 'UTC';
        }

        /** @var array<string, list<array{dst: bool, offset: int, timezone_id: ?string}>> $data */
        $data = require __DIR__.'/TimezoneAbbreviationsData.php';
        $key = strtolower($abbr);

        if (isset($data[$key])) {
            $firstFound = null;
            foreach ($data[$key] as $entry) {
                $timezoneId = $entry['timezone_id'] ?? null;
                if (!\is_string($timezoneId) || '' === $timezoneId) {
                    continue;
                }
                if (null === $firstFound) {
                    $firstFound = $timezoneId;
                    if (-1 === $gmtoffset) {
                        return $timezoneId;
                    }
                }
                if ($entry['offset'] === $gmtoffset) {
                    return $timezoneId;
                }
            }
            if (null !== $firstFound) {
                return $firstFound;
            }
        }

        if (-1 === $gmtoffset || -1 === $isdst) {
            return false;
        }

        /** @var list<array{dst: int, offset: int, timezone_id: string}> $fallback */
        $fallback = require __DIR__.'/TimezoneFallbackData.php';
        foreach ($fallback as $entry) {
            if ($entry['offset'] === $gmtoffset && $entry['dst'] === $isdst) {
                return $entry['timezone_id'];
            }
        }

        return false;
    }

    public static function validateTimezoneId(string $timezone): string
    {
        $timezone = trim($timezone);
        if ('' === $timezone) {
            self::throwInvalidTimezone($timezone);
        }
        $canonicalOffset = self::canonicalNumericTimezoneId($timezone);
        if (null !== $canonicalOffset) {
            return $canonicalOffset;
        }
        if (self::zoneinfoPath($timezone)) {
            return $timezone;
        }
        self::throwInvalidTimezone($timezone);
    }

    /** php-src ext/date/php_date.c — date_default_timezone_set() validation without throwing. */
    public static function timezoneIdIsValid(string $timezone): bool
    {
        $timezone = trim($timezone);
        if ('' === $timezone) {
            return false;
        }
        if (null !== self::canonicalNumericTimezoneId($timezone)) {
            return true;
        }

        return null !== self::zoneinfoPath($timezone);
    }

    /**
     * Fixed numeric offset from a timezone id (+0530 / +05:30), or null.
     */
    public static function parseNumericTimezoneOffset(string $timezone): ?int
    {
        $timezone = trim($timezone);
        if (1 !== preg_match('/^([+-])(\d{2}):?(\d{2})$/', $timezone, $matches)) {
            return null;
        }
        $hours = (int) $matches[2];
        $minutes = (int) $matches[3];
        if ($hours > 18 || $minutes >= 60) {
            return null;
        }
        $seconds = $hours * 3600 + $minutes * 60;

        return '-' === $matches[1] ? -$seconds : $seconds;
    }

    /**
     * php-src timelib canonical spelling (+HH:MM) for numeric offset ids.
     */
    public static function canonicalNumericTimezoneId(string $timezone): ?string
    {
        $offset = self::parseNumericTimezoneOffset($timezone);
        if (null === $offset) {
            return null;
        }

        return self::formatOffset($offset);
    }

    /**
     * @return array{timestamp: int, microsecond: int, timezone?: string}
     */
    public static function parseDateTime(string $time, string $tzName, ?int $baseTimestamp = null): array
    {
        $time = trim($time);
        if ('' === $time) {
            return self::readNow();
        }
        if ('now' === strtolower($time)) {
            return self::readNow();
        }
        $base = $baseTimestamp ?? self::readNow()['timestamp'];
        $extended = self::tryParseExtendedDateTimeString($time, $tzName, $base);
        if (null !== $extended) {
            return $extended;
        }

        $relative = self::tryParseRelativeDateTimeModifier($time, $tzName, $base);
        if (null !== $relative) {
            return $relative;
        }

        return self::parseDateTimeAbsolute($time, $tzName);
    }

    /**
     * php-src php_date_initialize / timelib — ctor and strtotime relative modifiers (#18327, #10742).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function tryParseRelativeDateTimeModifier(string $time, string $tzName, int $base): ?array
    {
        if (str_starts_with($time, '+') || str_starts_with($time, '-')) {
            $compound = self::tryApplyCompoundSignedRelativeDelta($base, $time, $tzName);
            if (null !== $compound) {
                return ['timestamp' => $compound, 'microsecond' => 0];
            }
        }
        if (1 === preg_match(
            '/^[+-]?\d+\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years|weekday|weekdays)$/i',
            $time
        )) {
            $modifier = $time;
            if (!preg_match('/^[+-]/', $modifier)) {
                $modifier = '+'.$modifier;
            }
            try {
                $timestamp = self::modifyRelative($base, $modifier, $tzName);

                return ['timestamp' => $timestamp, 'microsecond' => 0];
            } catch (NativeDateMalformedStringException) {
                return null;
            }
        }

        return null;
    }

    /**
     * php-src timelib relative grammar — next weekday, first/last day of month, date + modifier (#11327).
     *
     * @return array{timestamp: int, microsecond: int, timezone?: string}|null
     */
    private static function tryParseExtendedDateTimeString(string $time, string $tzName, int $base): ?array
    {
        // php-src timelib TIMELIB_SPECIAL_WEEKDAY — business-day steps (#25262).
        if (1 === preg_match('/^next\s+weekdays?$/i', $time)) {
            return self::specialWeekdayParseResult(1, $base, $tzName, false);
        }
        if (1 === preg_match('/^(last|previous)\s+weekdays?$/i', $time)) {
            return self::specialWeekdayParseResult(-1, $base, $tzName, false);
        }
        if (1 === preg_match('/^this\s+weekdays?$/i', $time)) {
            return self::specialWeekdayParseResult(0, $base, $tzName, false);
        }
        // Bare "weekday(s)" is parsed as TIMELIB_WEEKDAY with multiplier Monday (#25262).
        if (1 === preg_match('/^weekdays?$/i', $time)) {
            return self::weekdayParseResult('bare', 'monday', $base, $tzName);
        }
        if (1 === preg_match(
            '/^next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $time,
            $matches
        )) {
            return self::weekdayParseResult('next', strtolower($matches[1]), $base, $tzName);
        }
        if (1 === preg_match(
            '/^(last|previous|this)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $time,
            $matches
        )) {
            return self::weekdayParseResult(strtolower($matches[1]), strtolower($matches[2]), $base, $tzName);
        }
        if (1 === preg_match(
            '/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $time,
            $matches
        )) {
            return self::weekdayParseResult('bare', strtolower($matches[1]), $base, $tzName);
        }
        if (1 === preg_match(
            '/^(first|second|third|fourth|fifth|last)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+of\s+([A-Za-z]+)\s+(\d{4})$/i',
            $time,
            $matches
        )) {
            return self::weekdayOfMonthParseResult(
                strtolower($matches[1]),
                strtolower($matches[2]),
                $matches[3],
                (int) $matches[4],
                $tzName
            );
        }
        // php-src timelib — Nth weekday of this|next|last month (#19550).
        if (1 === preg_match(
            '/^(first|second|third|fourth|fifth|last)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+of\s+(this|next|last)\s+month$/i',
            $time,
            $matches
        )) {
            return self::weekdayOfRelativeMonthParseResult(
                strtolower($matches[1]),
                strtolower($matches[2]),
                strtolower($matches[3]),
                $base,
                $tzName
            );
        }
        if (1 === preg_match('/^midnight$/i', $time)) {
            return self::timeOfDayOnBase($base, 0, 0, 0, $tzName);
        }
        if (1 === preg_match('/^noon$/i', $time)) {
            return self::timeOfDayOnBase($base, 12, 0, 0, $tzName);
        }
        if (1 === preg_match('/^(today|tomorrow|yesterday)$/i', $time, $matches)) {
            return self::dayWordMidnightParseResult(strtolower($matches[1]), $base, $tzName);
        }
        if (1 === preg_match('/^(today|tomorrow|yesterday)\s+(.+)$/i', $time, $matches)) {
            return self::dayWordWithTimeParseResult(strtolower($matches[1]), trim($matches[2]), $base, $tzName);
        }
        if (1 === preg_match('/^last day of ([A-Za-z]+)\s+(\d{4})$/i', $time, $matches)) {
            $month = self::englishMonthToNumber($matches[1]);
            if (null === $month) {
                return null;
            }
            $year = (int) $matches[2];

            return [
                'timestamp' => self::mktimeInTimezone(
                    $year,
                    $month,
                    self::daysInMonth($year, $month),
                    0,
                    0,
                    0,
                    $tzName
                ),
                'microsecond' => 0,
            ];
        }
        if (1 === preg_match('/^first day of ([A-Za-z]+)\s+(\d{4})$/i', $time, $matches)) {
            $month = self::englishMonthToNumber($matches[1]);
            if (null === $month) {
                return null;
            }
            $year = (int) $matches[2];

            return [
                'timestamp' => self::mktimeInTimezone($year, $month, 1, 0, 0, 0, $tzName),
                'microsecond' => 0,
            ];
        }
        // php-src parse_date.re — bare "first|last day of" ≡ this month; preserve clock (#23967).
        if (1 === preg_match('/^(first|last) day of$/i', $time, $matches)) {
            return self::monthBoundaryParseResult(
                strtolower($matches[1]),
                'this',
                $base,
                $tzName
            );
        }
        // php-src — first|last day of ±N month(s)|year(s) (#23987).
        if (1 === preg_match(
            '/^(first|last) day of\s+([+-]?\s*\d+)\s+(month|months|year|years)$/i',
            $time,
            $matches
        )) {
            return self::monthBoundaryOfSignedRelativeParseResult(
                strtolower($matches[1]),
                $matches[2],
                strtolower($matches[3]),
                $base,
                $tzName
            );
        }
        if (1 === preg_match('/^(first|last) day of (next|this|last|previous) month$/i', $time, $matches)) {
            $when = strtolower($matches[2]);
            if ('previous' === $when) {
                $when = 'last';
            }

            return self::monthBoundaryParseResult(
                strtolower($matches[1]),
                $when,
                $base,
                $tzName
            );
        }
        // php-src parse_date.re — first|last day of MonthName next|last|this|previous year (#23936).
        if (1 === preg_match(
            '/^(first|last) day of ([A-Za-z]+)\s+(next|last|this|previous)\s+year$/i',
            $time,
            $matches
        )) {
            return self::monthBoundaryOfRelativeYearParseResult(
                strtolower($matches[1]),
                $matches[2],
                strtolower($matches[3]),
                $base,
                $tzName
            );
        }
        if (1 === preg_match('/^(next|last|this) month$/i', $time, $matches)) {
            return self::monthOffsetParseResult(strtolower($matches[1]), $base, $tzName);
        }
        if (1 === preg_match('/^(last|next|this) year$/i', $time, $matches)) {
            return self::yearOffsetParseResult(strtolower($matches[1]), $base, $tzName);
        }
        if (1 === preg_match('/^(next|last|this) week$/i', $time, $matches)) {
            return self::weekOffsetParseResult(strtolower($matches[1]), $base, $tzName);
        }
        // php-src — "monday this week" (weekday within ISO week; may be past) (#23936).
        if (1 === preg_match(
            '/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+(this|next|last|previous)\s+week$/i',
            $time,
            $matches
        )) {
            $when = strtolower($matches[2]);
            if ('previous' === $when) {
                $when = 'last';
            }

            return self::weekdayOfWeekParseResult(strtolower($matches[1]), $when, $base, $tzName);
        }
        // php-src — "next week Monday" (same as "Monday next week"; week token first) (#24018).
        if (1 === preg_match(
            '/^(this|next|last|previous)\s+week\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $time,
            $matches
        )) {
            $when = strtolower($matches[1]);
            if ('previous' === $when) {
                $when = 'last';
            }

            return self::weekdayOfWeekParseResult(strtolower($matches[2]), $when, $base, $tzName);
        }
        // php-src parse_date.re — back of / front of hour24 (+ optional date remainder) (#23936, #24395).
        if (1 === preg_match('/^(back|front)\s+of\s+(.+)$/i', $time, $matches)) {
            return self::backFrontOfHourParseResult(strtolower($matches[1]), trim($matches[2]), $base, $tzName);
        }
        if (1 === preg_match('/^(.+?) (last|next|this) year$/i', $time, $matches)) {
            $yearRelative = self::yearRelativeMonthDayParseResult(
                strtolower($matches[2]),
                trim($matches[1]),
                $base,
                $tzName
            );
            if (null !== $yearRelative) {
                return $yearRelative;
            }
        }
        if (1 === preg_match('/^(last|next|this) year (.+)$/i', $time, $matches)) {
            $yearRelative = self::yearRelativeMonthDayParseResult(
                strtolower($matches[1]),
                trim($matches[2]),
                $base,
                $tzName
            );
            if (null !== $yearRelative) {
                return $yearRelative;
            }
        }
        // Absolute calendar date + relative phrase (#19534) — timelib parse_date.re combination.
        $absoluteRelative = self::tryParseAbsoluteDateWithRelativeSuffix($time, $tzName);
        if (null !== $absoluteRelative) {
            return $absoluteRelative;
        }
        if (1 === preg_match(
            '/^(.+?)\s+([+-].+)$/',
            $time,
            $matches
        )) {
            try {
                $parsed = self::parseDateTimeAbsolute(trim($matches[1]), $tzName);
                $modifier = trim($matches[2]);
                $compound = self::tryApplyCompoundSignedRelativeDelta($parsed['timestamp'], $modifier, $tzName);
                if (null !== $compound) {
                    $result = ['timestamp' => $compound, 'microsecond' => $parsed['microsecond']];
                    if (isset($parsed['timezone'])) {
                        $result['timezone'] = $parsed['timezone'];
                    }

                    return $result;
                }
                // Single-unit fallback (e.g. "+1 day") when compound grammar does not consume.
                if (1 === preg_match(
                    '/^[+-]\s*\d+\s+(?:second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years|weekday|weekdays)$/i',
                    $modifier
                )) {
                    $normalized = preg_replace('/\s+/', ' ', $modifier) ?? $modifier;
                    $timestamp = self::modifyRelative($parsed['timestamp'], $normalized, $tzName);
                    $result = ['timestamp' => $timestamp, 'microsecond' => $parsed['microsecond']];
                    if (isset($parsed['timezone'])) {
                        $result['timezone'] = $parsed['timezone'];
                    }

                    return $result;
                }
            } catch (NativeDateMalformedStringException) {
                // Fall through — absolute prefix may not be a date.
            }
        }

        return null;
    }

    /**
     * php-src timelib — absolute date/time followed by a relative phrase (#19534).
     *
     * Examples: "2020-01-15 next Monday", "2020-02-01 last day of this month".
     *
     * @return array{timestamp: int, microsecond: int, timezone?: string}|null
     */
    private static function tryParseAbsoluteDateWithRelativeSuffix(string $time, string $tzName): ?array
    {
        $weekday = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday';
        $ordinal = 'first|second|third|fourth|fifth|last';
        $suffixPatterns = [
            // Business-day unit after absolute date (#25262).
            '/^(.+?)\s+((?:next|last|previous|this)\s+weekdays?)$/i',
            '/^(.+?)\s+(weekdays?)$/i',
            '/^(.+?)\s+((?:next|last|previous|this)\s+(?:'.$weekday.'))$/i',
            // Week + weekday either order (#23936 / #24018).
            '/^(.+?)\s+((?:'.$weekday.')\s+(?:this|next|last|previous)\s+week)$/i',
            '/^(.+?)\s+((?:this|next|last|previous)\s+week\s+(?:'.$weekday.'))$/i',
            '/^(.+?)\s+((?:'.$weekday.'))$/i',
            '/^(.+?)\s+((?:first|last)\s+day\s+of\s+(?:next|this|last)\s+month)$/i',
            // first|last day of ±N month|year after absolute date (#23987).
            '/^(.+?)\s+((?:first|last)\s+day\s+of\s+[+-]?\s*\d+\s+(?:month|months|year|years))$/i',
            // Nth weekday of month — named year or this|next|last (#19550).
            '/^(.+?)\s+((?:'.$ordinal.')\s+(?:'.$weekday.')\s+of\s+(?:this|next|last)\s+month)$/i',
            '/^(.+?)\s+((?:'.$ordinal.')\s+(?:'.$weekday.')\s+of\s+[A-Za-z]+\s+\d{4})$/i',
            '/^(.+?)\s+((?:next|last|this)\s+(?:month|year|week))$/i',
            '/^(.+?)\s+((?:today|tomorrow|yesterday)(?:\s+.+)?)$/i',
            '/^(.+?)\s+(midnight|noon)$/i',
        ];
        foreach ($suffixPatterns as $pattern) {
            if (1 !== preg_match($pattern, $time, $matches)) {
                continue;
            }
            $absolute = trim($matches[1]);
            $relative = trim($matches[2]);
            if ('' === $absolute || '' === $relative || strcasecmp($absolute, $time) === 0) {
                continue;
            }
            try {
                $parsed = self::parseDateTimeAbsolute($absolute, $tzName);
            } catch (NativeDateMalformedStringException) {
                continue;
            }
            $useTz = $parsed['timezone'] ?? $tzName;
            $extended = self::tryParseExtendedDateTimeString($relative, $useTz, $parsed['timestamp']);
            if (null === $extended) {
                $extended = self::tryParseRelativeDateTimeModifier($relative, $useTz, $parsed['timestamp']);
            }
            if (null === $extended) {
                continue;
            }
            if (!isset($extended['timezone']) && isset($parsed['timezone'])) {
                $extended['timezone'] = $parsed['timezone'];
            }

            return $extended;
        }

        return null;
    }

    /**
     * @return array{timestamp: int, microsecond: int, timezone?: string}
     */
    private static function parseDateTimeAbsolute(string $time, string $tzName): array
    {
        if (str_starts_with($time, '@')) {
            $unix = substr($time, 1);
            if ('' === $unix || !ctype_digit($unix)) {
                self::throwMalformedDateTime($time);
            }

            // php-src ext/date/php_date.c — @ unix timestamps use offset timezone +00:00 (zone_type 1).
            return ['timestamp' => (int) $unix, 'microsecond' => 0, 'timezone' => '+00:00'];
        }
        // php-src parse_date.re — ISO week / ordinal day before numeric-timestamp fallback (#25263).
        $isoOrOrdinal = self::tryParseIsoWeekOrOrdinalAbsolute($time, $tzName);
        if (null !== $isoOrOrdinal) {
            return $isoOrOrdinal;
        }
        // Seven-digit YYYYDDD with invalid day must not become a unix timestamp (#25263).
        if (1 === preg_match('/^\d{7}$/', $time)) {
            self::throwMalformedDateTime($time);
        }
        if (1 === preg_match('/^\d+$/', $time)) {
            return ['timestamp' => (int) $time, 'microsecond' => 0];
        }
        if (1 === preg_match(
            // php-src parse_date.re — HH:MM accepted; seconds default 0 (#25309).
            '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?(?:Z|([+-]\d{2}:?\d{2}))?$/',
            $time,
            $matches
        )) {
            $hour = isset($matches[4]) && '' !== $matches[4] ? (int) $matches[4] : 0;
            $minute = isset($matches[5]) && '' !== $matches[5] ? (int) $matches[5] : 0;
            $second = isset($matches[6]) && '' !== $matches[6] ? (int) $matches[6] : 0;
            $microsecond = 0;
            if (isset($matches[7]) && '' !== $matches[7]) {
                $microsecond = (int) \str_pad(\substr($matches[7], 0, 6), 6, '0', STR_PAD_RIGHT);
            }
            $useTz = $tzName;
            if (str_ends_with($time, 'Z')) {
                $useTz = 'UTC';
            } elseif (isset($matches[8]) && '' !== $matches[8]) {
                $embedded = self::canonicalNumericTimezoneId($matches[8]);
                if (null === $embedded) {
                    self::throwMalformedDateTime($time);
                }
                $useTz = $embedded;
            }

            return [
                'timestamp' => self::mktimeInTimezone(
                    (int) $matches[1],
                    (int) $matches[2],
                    (int) $matches[3],
                    $hour,
                    $minute,
                    $second,
                    $useTz
                ),
                'microsecond' => $microsecond,
                'timezone' => $useTz !== $tzName ? $useTz : null,
                'utc_z' => str_ends_with($time, 'Z'),
            ];
        }
        if (1 === preg_match(
            // php-src parse_date.re — HH:MM accepted; seconds default 0 (#25309).
            '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?\s+([A-Za-z][A-Za-z0-9_+\/-]*(?:\/[A-Za-z][A-Za-z0-9_+\/-]*)*)$/',
            $time,
            $matches
        )) {
            $hour = isset($matches[4]) && '' !== $matches[4] ? (int) $matches[4] : 0;
            $minute = isset($matches[5]) && '' !== $matches[5] ? (int) $matches[5] : 0;
            $second = isset($matches[6]) && '' !== $matches[6] ? (int) $matches[6] : 0;
            $microsecond = 0;
            if (isset($matches[7]) && '' !== $matches[7]) {
                $microsecond = (int) \str_pad(\substr($matches[7], 0, 6), 6, '0', STR_PAD_RIGHT);
            }
            try {
                $useTz = self::validateTimezoneId($matches[8]);
            } catch (\PHPCompiler\VM\NativeDateInvalidTimeZoneException) {
                self::throwMalformedDateTime($time);
            }

            return [
                'timestamp' => self::mktimeInTimezone(
                    (int) $matches[1],
                    (int) $matches[2],
                    (int) $matches[3],
                    $hour,
                    $minute,
                    $second,
                    $useTz
                ),
                'microsecond' => $microsecond,
                'timezone' => $useTz,
            ];
        }
        if (1 === preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $time, $matches)) {
            $month = self::englishMonthToNumber($matches[2]);
            if (null === $month) {
                self::throwMalformedDateTime($time);
            }

            return [
                'timestamp' => self::mktimeInTimezone(
                    (int) $matches[3],
                    $month,
                    (int) $matches[1],
                    0,
                    0,
                    0,
                    $tzName
                ),
                'microsecond' => 0,
            ];
        }
        if (1 === preg_match('/^([A-Za-z]+)\s+(\d{1,2}),\s+(\d{4})$/', $time, $matches)) {
            $month = self::englishMonthToNumber($matches[1]);
            if (null === $month) {
                self::throwMalformedDateTime($time);
            }

            return [
                'timestamp' => self::mktimeInTimezone(
                    (int) $matches[3],
                    $month,
                    (int) $matches[2],
                    0,
                    0,
                    0,
                    $tzName
                ),
                'microsecond' => 0,
            ];
        }

        self::throwMalformedDateTime($time);
    }

    /**
     * php-src parse_date.re — absolute ISO week (YYYYWww / YYYY-Www[-D]) and ordinal day (YYYYDDD / YYYY-DDD) (#25263).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function tryParseIsoWeekOrOrdinalAbsolute(string $time, string $tzName): ?array
    {
        // Compact / hyphenated ISO week: YYYYWww, YYYYWwwD, YYYYWww-D, YYYY-Www, YYYY-Www-D.
        if (1 === preg_match('/^(\d{4})W(\d{2})([1-7])?$/i', $time, $matches)
            || 1 === preg_match('/^(\d{4})W(\d{2})-([0-7])$/i', $time, $matches)
            || 1 === preg_match('/^(\d{4})-W(\d{2})(?:-([0-7]))?$/i', $time, $matches)
        ) {
            $isoYear = (int) $matches[1];
            $isoWeek = (int) $matches[2];
            if ($isoWeek < 1 || $isoWeek > 53) {
                return null;
            }
            $isoDay = isset($matches[3]) && '' !== $matches[3] ? (int) $matches[3] : 1;
            [$year, $month, $day] = self::ymdFromIsoDate($isoYear, $isoWeek, $isoDay);

            return [
                'timestamp' => self::mktimeInTimezone($year, $month, $day, 0, 0, 0, $tzName),
                'microsecond' => 0,
            ];
        }

        // Ordinal day-of-year: YYYYDDD or YYYY-DDD (day 001–366; 366 may overflow non-leap years).
        $ordinalMatch = null;
        if (7 === \strlen($time) && 1 === preg_match('/^(\d{4})(\d{3})$/', $time, $ordinalMatch)) {
            // compact YYYYDDD
        } elseif (1 === preg_match('/^(\d{4})-(\d{3})$/', $time, $ordinalMatch)) {
            // hyphenated YYYY-DDD
        } else {
            $ordinalMatch = null;
        }
        if (null !== $ordinalMatch) {
            $year = (int) $ordinalMatch[1];
            $ordinal = (int) $ordinalMatch[2];
            if ($ordinal < 1 || $ordinal > 366) {
                return null;
            }

            return [
                'timestamp' => self::mktimeInTimezone($year, 1, $ordinal, 0, 0, 0, $tzName),
                'microsecond' => 0,
            ];
        }

        return null;
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

            return self::withOffsetTimezoneMetadata(self::parseResultFromTimestamp((int) $unix, 0), 0);
        }
        if (1 === preg_match('/^\d+$/', $date)) {
            return self::parseResultFromTimestamp((int) $date, 0);
        }
        if (1 === preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?)?\s+([A-Za-z][A-Za-z0-9_+\/-]*(?:\/[A-Za-z][A-Za-z0-9_+\/-]*)*)$/',
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
            try {
                $tzId = self::validateTimezoneId($matches[8]);
            } catch (\PHPCompiler\VM\NativeDateInvalidTimeZoneException) {
                // Bare abbreviations (GMT/EST/…) are not always valid DateTimeZone ids.
                if (!\str_contains($matches[8], '/') && null !== self::abbreviationOffsetAndDst($matches[8])) {
                    $result = self::finalizeParsedDateComponents([
                        'year' => (int) $matches[1],
                        'month' => (int) $matches[2],
                        'day' => (int) $matches[3],
                        'hour' => $hasTime ? (int) $matches[4] : false,
                        'minute' => $hasTime ? (int) $matches[5] : false,
                        'second' => $hasTime ? (int) $matches[6] : false,
                        'fraction' => $fraction,
                    ], null);

                    return self::withAbbreviationTimezoneMetadata($result, $matches[8]);
                }

                return self::parseUnrecognizedDateString($date);
            }
            $result = self::finalizeParsedDateComponents([
                'year' => (int) $matches[1],
                'month' => (int) $matches[2],
                'day' => (int) $matches[3],
                'hour' => $hasTime ? (int) $matches[4] : false,
                'minute' => $hasTime ? (int) $matches[5] : false,
                'second' => $hasTime ? (int) $matches[6] : false,
                'fraction' => $fraction,
            ], $tzId);

            return self::withParsedTimezoneToken($result, $matches[8]);
        }
        if (1 === preg_match('/^([A-Za-z]+)\s+(\d{1,2}),\s+(\d{4})$/', $date, $matches)) {
            $month = self::englishMonthToNumber($matches[1]);
            if (null === $month) {
                return self::parseUnrecognizedDateString($date);
            }

            return self::finalizeParsedDateComponents([
                'year' => (int) $matches[3],
                'month' => $month,
                'day' => (int) $matches[2],
                'hour' => false,
                'minute' => false,
                'second' => false,
                'fraction' => false,
            ], $tzName);
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

        $relativeWeekday = self::tryParseRelativeWeekdayForDateParse($date);
        if (null !== $relativeWeekday) {
            return $relativeWeekday;
        }

        try {
            $parsed = self::parseDateTime($date, $tzName);
            $result = self::parseResultFromTimestamp($parsed['timestamp'], $parsed['microsecond']);

            return self::applyParseTimezoneMetadata($result, $parsed, $date);
        } catch (NativeDateMalformedStringException) {
            return self::parseUnrecognizedDateString($date);
        }
    }

    /**
     * php-src date_parse() — relative weekday modifiers keep false calendar fields (#14163).
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
     *   is_localtime: bool,
     *   relative: array{
     *     year: int,
     *     month: int,
     *     day: int,
     *     hour: int,
     *     minute: int,
     *     second: int,
     *     weekday: int
     *   }
     * }|null
     */
    private static function tryParseRelativeWeekdayForDateParse(string $date): ?array
    {
        $modifier = null;
        $weekday = null;
        if (1 === preg_match(
            '/^next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $date,
            $matches
        )) {
            $modifier = 'next';
            $weekday = strtolower($matches[1]);
        } elseif (1 === preg_match(
            '/^(last|previous|this)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $date,
            $matches
        )) {
            $modifier = strtolower($matches[1]);
            $weekday = strtolower($matches[2]);
        } elseif (1 === preg_match(
            '/^(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/i',
            $date,
            $matches
        )) {
            $modifier = 'bare';
            $weekday = strtolower($matches[1]);
        }
        if (null === $weekday) {
            return null;
        }
        $weekdayNum = self::weekdayNameToNumber($weekday);
        if ($weekdayNum < 0) {
            return null;
        }
        $relativeDay = \in_array($modifier, ['last', 'previous'], true) ? -7 : 0;
        $result = self::parseResultFromComponents([
            'year' => false,
            'month' => false,
            'day' => false,
            'hour' => false,
            'minute' => false,
            'second' => false,
            'fraction' => false,
        ]);
        $result['relative'] = [
            'year' => 0,
            'month' => 0,
            'day' => $relativeDay,
            'hour' => 0,
            'minute' => 0,
            'second' => 0,
            'weekday' => $weekdayNum,
        ];

        return $result;
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
            $failure = self::buildCreateFromFormatFailureErrors($format, $time);

            return self::failedParseResult($failure['errors'], $failure['error_count']);
        }
        $normalized = self::warnInvalidCalendarComponents($matched);
        $result = self::parseResultFromComponents($normalized['components']);
        if ([] !== $normalized['warnings']) {
            $result['warning_count'] = \count($normalized['warnings']);
            $result['warnings'] = $normalized['warnings'];
        }

        // php-src PHP_FUNCTION(date_parse_from_format) — emit zone_* from format tokens T/e/O/P (#25487).
        return self::applyFromFormatTimezoneMetadata($result, $normalized['components']);
    }

    /**
     * @return array{timestamp: int, microsecond: int, timezone?: string}|false
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
        $fraction = $matched['fraction'] ?? false;
        $microsecond = false === $fraction ? 0 : (int) \round($fraction * 1_000_000);
        $useTz = isset($matched['timezone']) && \is_string($matched['timezone'])
            ? $matched['timezone']
            : $tzName;
        if (!self::formatStringHasTimeTokens($format)) {
            $now = self::readNow();
            $nowTm = self::withTimezone($useTz, static function () use ($now): ?array {
                return self::localtime($now['timestamp']);
            });
            if (null !== $nowTm) {
                if (false === $matched['hour']) {
                    $hour = self::tmInt($nowTm, 'tm_hour');
                }
                if (false === $matched['minute']) {
                    $minute = self::tmInt($nowTm, 'tm_min');
                }
                if (false === $matched['second']) {
                    $second = self::tmInt($nowTm, 'tm_sec');
                }
            }
        }

        try {
            $result = [
                'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $useTz),
                'microsecond' => $microsecond,
            ];
            if ($useTz !== $tzName) {
                $result['timezone'] = $useTz;
            }

            return $result;
        } catch (NativeDateMalformedStringException) {
            return false;
        }
    }

    /**
     * php-src PHP_FUNCTION(strtotime) — natural-language / relative timestamps (#10742, #11327).
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
        $relative = self::tryParseRelativeDateTimeModifier($time, $tzName, $base);
        if (null !== $relative) {
            return $relative['timestamp'];
        }
        try {
            $parsed = self::parseDateTime($time, $tzName, $base);

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
    /**
     * @param array<int, string> $errors
     */
    private static function failedParseResult(array $errors, ?int $errorCount = null): array
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
            'error_count' => $errorCount ?? \count($errors),
            'errors' => $errors,
            'is_localtime' => false,
        ];
    }

    /**
     * php-src ext/date/lib/parse_date.c — timelib_parse_from_format error accumulation (#14173).
     *
     * error_count is the number of recorded messages (not unique position keys).
     *
     * @return array{errors: array<int, string>, error_count: int}
     */
    private static function buildCreateFromFormatFailureErrors(string $format, string $time): array
    {
        if (self::$createFromFormatTrailingData) {
            self::$createFromFormatTrailingData = false;

            return ['errors' => [10 => 'Trailing data'], 'error_count' => 1];
        }

        /** @var list<array{0: int, 1: string}> $messages */
        $messages = [];
        $add = static function (int $position, string $message) use (&$messages): void {
            $messages[] = [$position, $message];
        };

        $bare = \str_starts_with($format, '!') ? \substr($format, 1) : $format;
        $timeLen = \strlen($time);
        $primary = self::primaryCreateFromFormatFailureMessage($bare, $time);

        if (\strlen($bare) > 1 && \preg_match('/[YymdHisuvGUeTOPM]/', $bare)) {
            $add(0, 'The format separator does not match');
            $add(0, $primary);
            $add($timeLen, 'Not enough data available to satisfy format');
        } else {
            if ($timeLen > 0) {
                $add(0, 'Trailing data');
            }
            $add(0, $primary);
        }

        $errors = [];
        foreach ($messages as [$position, $message]) {
            $errors[$position] = $message;
        }

        return ['errors' => $errors, 'error_count' => \count($messages)];
    }

    private static function primaryCreateFromFormatFailureMessage(string $format, string $time): string
    {
        if (\str_contains($format, 'Y')) {
            return 'A four digit year could not be found';
        }
        if (\str_contains($format, 'y')) {
            return 'A two digit year could not be found';
        }

        return 'Not enough data available to satisfy format';
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
            'year' => (self::tmInt($tm, 'tm_year') + 1900),
            'month' => (self::tmInt($tm, 'tm_mon') + 1),
            'day' => self::tmInt($tm, 'tm_mday'),
            'hour' => self::tmInt($tm, 'tm_hour'),
            'minute' => self::tmInt($tm, 'tm_min'),
            'second' => self::tmInt($tm, 'tm_sec'),
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
     * php-src timelib — named timezone suffix metadata for date_parse() (#13405).
     *
     * @param array{
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
     * } $result
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
     *   is_localtime: bool,
     *   zone_type: int,
     *   tz_id: string
     * }
     */
    private static function withNamedTimezoneMetadata(array $result, string $tzId): array
    {
        $result['is_localtime'] = true;
        $result['zone_type'] = 3;
        // php-src timelib — UTC identifier also exposes tz_abbr (#25486 / #25487).
        if (0 === \strcasecmp($tzId, 'UTC')) {
            $result['tz_abbr'] = 'UTC';
        }
        $result['tz_id'] = $tzId;

        return $result;
    }

    /**
     * php-src timelib — TIMELIB_ZONETYPE_ABBR (2) metadata for format token T (#25487).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function withAbbreviationTimezoneMetadata(array $result, string $abbr): array
    {
        $meta = self::abbreviationOffsetAndDst($abbr);
        $result['is_localtime'] = true;
        $result['zone_type'] = 2;
        $result['zone'] = $meta['offset'] ?? 0;
        $result['is_dst'] = $meta['dst'] ?? false;
        $result['tz_abbr'] = $abbr;

        return $result;
    }

    /**
     * Apply timezone keys from date_parse_from_format match components (#25487).
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $components
     *
     * @return array<string, mixed>
     */
    private static function applyFromFormatTimezoneMetadata(array $result, array $components): array
    {
        if (!isset($components['timezone']) || !\is_string($components['timezone']) || '' === $components['timezone']) {
            return $result;
        }
        $tz = $components['timezone'];
        $kind = $components['timezone_kind'] ?? null;
        $abbr = $components['timezone_abbr'] ?? null;

        if ('offset' === $kind || null !== self::parseNumericTimezoneOffset($tz)) {
            $offset = self::parseNumericTimezoneOffset($tz);
            if (null === $offset) {
                return $result;
            }
            $result['is_localtime'] = true;

            return self::withOffsetTimezoneMetadata($result, $offset);
        }

        // Token T: UTC is zone_type ID; other abbreviations are TIMELIB_ZONETYPE_ABBR.
        if ('abbr' === $kind && \is_string($abbr) && '' !== $abbr) {
            if (0 === \strcasecmp($abbr, 'UTC')) {
                return self::withNamedTimezoneMetadata($result, 'UTC');
            }

            return self::withAbbreviationTimezoneMetadata($result, $abbr);
        }

        return self::withNamedTimezoneMetadata($result, $tz);
    }

    /**
     * @return array{offset: int, dst: bool}|null
     */
    private static function abbreviationOffsetAndDst(string $abbr): ?array
    {
        /** @var array<string, list<array{dst: bool, offset: int, timezone_id: ?string}>> $data */
        $data = require __DIR__.'/TimezoneAbbreviationsData.php';
        $entries = $data[\strtolower($abbr)] ?? null;
        if (!\is_array($entries) || [] === $entries) {
            return null;
        }
        $entry = $entries[0];

        return [
            'offset' => (int) $entry['offset'],
            'dst' => (bool) $entry['dst'],
        ];
    }

    private static function englishMonthToNumber(string $monthName): ?int
    {
        static $map = [
            'january' => 1,
            'february' => 2,
            'march' => 3,
            'april' => 4,
            'may' => 5,
            'june' => 6,
            'july' => 7,
            'august' => 8,
            'september' => 9,
            'october' => 10,
            'november' => 11,
            'december' => 12,
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'sept' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];

        return $map[strtolower($monthName)] ?? null;
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
     *   timezone?: string
     * }|false
     */
    private static function matchFormatComponents(string $format, string $time): array|false
    {
        self::$createFromFormatTrailingData = false;
        $bangReset = false;
        $pipeReset = false;
        $allowTrailing = false;
        if (\str_starts_with($format, '!')) {
            $bangReset = true;
            $format = \substr($format, 1);
        }
        $pos = 0;
        $timeLen = \strlen($time);
        $formatHasFractionToken = false;
        $components = [
            'year' => false,
            'month' => false,
            'day' => false,
            'hour' => false,
            'minute' => false,
            'second' => false,
            'fraction' => false,
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
                case '|':
                    // timelib: reset unparsed fields to Unix epoch after a successful parse (#22836).
                    $pipeReset = true;

                    break;
                case '+':
                    // timelib: trailing data after a successful parse is allowed (#22836).
                    $allowTrailing = true;

                    break;
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
                case 'M':
                    // Textual month (Jan–Dec) — php-src __DATE__ / PHP_BUILD_DATE (#23231).
                    $month = self::readFormatTextualMonth($time, $pos, $timeLen);
                    if (false === $month) {
                        return false;
                    }
                    $components['month'] = $month;

                    break;
                case 'n':
                    self::skipFormatWhitespace($time, $pos, $timeLen);
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
                    // timelib skips whitespace so space-padded days ("Jan  1") parse (#23231).
                    self::skipFormatWhitespace($time, $pos, $timeLen);
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
                    $formatHasFractionToken = true;
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
                    $components['year'] = (self::tmInt($tm, 'tm_year') + 1900);
                    $components['month'] = (self::tmInt($tm, 'tm_mon') + 1);
                    $components['day'] = self::tmInt($tm, 'tm_mday');
                    $components['hour'] = self::tmInt($tm, 'tm_hour');
                    $components['minute'] = self::tmInt($tm, 'tm_min');
                    $components['second'] = self::tmInt($tm, 'tm_sec');

                    break;
                case 'e':
                    $tzId = self::readFormatTimezoneIdentifier($time, $pos, $timeLen);
                    if (false === $tzId) {
                        return false;
                    }
                    $components['timezone'] = $tzId;
                    $components['timezone_kind'] = 'id';

                    break;
                case 'T':
                    $abbr = self::readFormatTimezoneAbbreviationRaw($time, $pos, $timeLen);
                    if (false === $abbr) {
                        return false;
                    }
                    $resolved = self::timezoneNameFromAbbr($abbr);
                    if (false === $resolved) {
                        return false;
                    }
                    $components['timezone'] = $resolved;
                    $components['timezone_kind'] = 'abbr';
                    $components['timezone_abbr'] = $abbr;

                    break;
                case 'P':
                    $tzId = self::readFormatTimezoneOffset($time, $pos, $timeLen, true);
                    if (false === $tzId) {
                        return false;
                    }
                    $components['timezone'] = $tzId;
                    $components['timezone_kind'] = 'offset';

                    break;
                case 'O':
                    $tzId = self::readFormatTimezoneOffset($time, $pos, $timeLen, false);
                    if (false === $tzId) {
                        return false;
                    }
                    $components['timezone'] = $tzId;
                    $components['timezone_kind'] = 'offset';

                    break;
                default:
                    if ($pos >= $timeLen || $time[$pos] !== $fc) {
                        return false;
                    }
                    ++$pos;
            }
        }
        if ($pos !== $timeLen) {
            if (!$allowTrailing) {
                self::$createFromFormatTrailingData = true;

                return false;
            }
            // `+`: ignore trailing input after a successful format match (#22836).
        }
        if ($bangReset || $pipeReset) {
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
        if (!$formatHasFractionToken) {
            $components['fraction'] = false;
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
     *   fraction: float|false
     * } $components
     *
     * @return array{components: array<string, int|false|float|false>, warnings: array<int, string>}
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
     *   fraction: float|false
     * } $components
     *
     * @return array{components: array<string, int|false|float|false>, warnings: array<int, string>}
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
    private static function finalizeParsedDateComponents(array $components, ?string $tzName = null): array
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
                $timestamp = self::mktimeInTimezone(
                    $year,
                    $month,
                    $day,
                    $hour,
                    $minute,
                    $second,
                    $tzName ?? 'UTC'
                );
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
            $result['warnings'] = [10 => 'The parsed date was invalid'];

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

        return self::withZoneTypeZeroMetadata($result);
    }

    /**
     * php-src timelib — numeric offset suffix metadata for date_parse() (#14806).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function withOffsetTimezoneMetadata(array $result, int $offsetSeconds): array
    {
        $result['zone_type'] = 1;
        $result['zone'] = $offsetSeconds;
        $result['is_dst'] = false;

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function withUtcTimezoneMetadata(array $result): array
    {
        // php-src timelib — trailing Z is TIMELIB_ZONETYPE_ABBR with tz_abbr="Z" (#25486).
        $result['zone_type'] = 2;
        $result['zone'] = 0;
        $result['is_dst'] = false;
        $result['tz_abbr'] = 'Z';

        return $result;
    }

    /**
     * Resolve free-form date_parse() timezone token to php-src zone keys (#25486).
     *
     * UTC → zone_type ID + tz_abbr; bare abbreviations → TIMELIB_ZONETYPE_ABBR;
     * IANA ids (e.g. America/New_York) → zone_type ID without tz_abbr.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function withParsedTimezoneToken(array $result, string $token): array
    {
        if (0 === \strcasecmp($token, 'UTC')) {
            return self::withNamedTimezoneMetadata($result, 'UTC');
        }
        // Bare abbreviations (no region slash) that timelib treats as TIMELIB_ZONETYPE_ABBR.
        if (!\str_contains($token, '/') && null !== self::abbreviationOffsetAndDst($token)) {
            return self::withAbbreviationTimezoneMetadata($result, $token);
        }

        return self::withNamedTimezoneMetadata($result, $token);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function withZoneTypeZeroMetadata(array $result): array
    {
        $result['zone_type'] = 0;

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array{timestamp: int, microsecond: int, timezone?: string|null, utc_z?: bool} $parsed
     *
     * @return array<string, mixed>
     */
    private static function applyParseTimezoneMetadata(array $result, array $parsed, string $date): array
    {
        if (!empty($parsed['utc_z'])) {
            return self::withUtcTimezoneMetadata($result);
        }
        $embedded = $parsed['timezone'] ?? null;
        if (\is_string($embedded) && '' !== $embedded) {
            $offset = self::parseNumericTimezoneOffset($embedded);
            if (null !== $offset) {
                return self::withOffsetTimezoneMetadata($result, $offset);
            }

            return self::withParsedTimezoneToken($result, $embedded);
        }

        return $result;
    }

    private static function isValidCalendarDate(int $year, int $month, int $day): bool
    {
        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }

        return $day <= self::daysInMonth($year, $month);
    }

    /**
     * @return array{timestamp: int, microsecond: int, timezone?: string}|null
     */
    private static function weekdayParseResult(string $modifier, string $weekday, int $base, string $tzName): ?array
    {
        $timestamp = self::weekdayRelativeTimestamp($modifier, $weekday, $base, $tzName);
        if (false === $timestamp) {
            return null;
        }
        $tm = self::localtime($timestamp);
        if (null === $tm) {
            return null;
        }

        return [
            'timestamp' => self::mktimeInTimezone(
                self::tmInt($tm, 'tm_year') + 1900,
                self::tmInt($tm, 'tm_mon') + 1,
                self::tmInt($tm, 'tm_mday'),
                0,
                0,
                0,
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src tm2unixtime.c do_adjust_special_weekday — ±N business days (#25262).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function specialWeekdayParseResult(
        int $amount,
        int $base,
        string $tzName,
        bool $keepTime
    ): ?array {
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $dayDelta = self::specialWeekdayDayDelta($amount, self::tmInt($tm, 'tm_wday'));

        return [
            'timestamp' => self::mktimeInTimezone(
                self::tmInt($tm, 'tm_year') + 1900,
                self::tmInt($tm, 'tm_mon') + 1,
                self::tmInt($tm, 'tm_mday') + $dayDelta,
                $keepTime ? self::tmInt($tm, 'tm_hour') : 0,
                $keepTime ? self::tmInt($tm, 'tm_min') : 0,
                $keepTime ? self::tmInt($tm, 'tm_sec') : 0,
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /**
     * Calendar-day delta for TIMELIB_SPECIAL_WEEKDAY (php-src do_adjust_special_weekday).
     */
    private static function specialWeekdayDayDelta(int $count, int $dow): int
    {
        $dayDelta = intdiv($count, 5) * 7;
        $rem = $count % 5;

        if ($count > 0) {
            if (0 === $rem) {
                if (0 === $dow) {
                    $dayDelta -= 2;
                } elseif (6 === $dow) {
                    $dayDelta -= 1;
                }
            } elseif (6 === $dow) {
                $dayDelta += 1;
            } elseif ($dow + $rem > 5) {
                $dayDelta += 2;
            }
        } else {
            // Mirrors forward direction; also covers count==0 weekend snap to Monday.
            if (0 === $rem) {
                if (6 === $dow) {
                    $dayDelta += 2;
                } elseif (0 === $dow) {
                    $dayDelta += 1;
                }
            } elseif (0 === $dow) {
                $dayDelta -= 1;
            } elseif ($dow + $rem < 1) {
                $dayDelta -= 2;
            }
        }

        return $dayDelta + $rem;
    }

    /**
     * php-src timelib — first|second|…|fifth|last weekday of named month (#15058, #19550).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function weekdayOfMonthParseResult(
        string $which,
        string $weekday,
        string $monthName,
        int $year,
        string $tzName
    ): ?array {
        $month = self::englishMonthToNumber($monthName);
        if (null === $month) {
            return null;
        }

        return self::weekdayOfYearMonthParseResult($which, $weekday, $year, $month, $tzName);
    }

    /**
     * php-src timelib — Nth weekday of this|next|last month relative to $base (#19550).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function weekdayOfRelativeMonthParseResult(
        string $which,
        string $weekday,
        string $when,
        int $base,
        string $tzName
    ): ?array {
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900;
        $month = self::tmInt($tm, 'tm_mon') + 1;
        $monthDelta = match ($when) {
            'next' => 1,
            'last' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $monthDelta) {
            return null;
        }
        [$year, $month] = self::shiftYearMonth($year, $month, $monthDelta);

        return self::weekdayOfYearMonthParseResult($which, $weekday, $year, $month, $tzName);
    }

    /**
     * Resolve Nth/last weekday in a concrete year-month (timelib ordinal weekday grammar).
     *
     * Nth (1–5) starts at the first occurrence then adds (n-1) weeks — may overflow into
     * the next month (e.g. "fifth Monday of February 2020" → 2020-03-02).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function weekdayOfYearMonthParseResult(
        string $which,
        string $weekday,
        int $year,
        int $month,
        string $tzName
    ): ?array {
        $target = self::weekdayNameToNumber($weekday);
        if ($target < 0) {
            return null;
        }
        $ordinal = match ($which) {
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            'last' => 0,
            default => -1,
        };
        if ($ordinal < 0) {
            return null;
        }
        if (0 === $ordinal) {
            $day = self::weekdayInMonth($year, $month, $target, true);
            if ($day < 1) {
                return null;
            }

            return [
                'timestamp' => self::mktimeInTimezone($year, $month, $day, 0, 0, 0, $tzName),
                'microsecond' => 0,
            ];
        }
        $firstDay = self::weekdayInMonth($year, $month, $target, false);
        if ($firstDay < 1) {
            return null;
        }
        // timelib: first + (n-1)*7 — mktime normalizes overflow past month end (#19550).
        $day = $firstDay + (($ordinal - 1) * 7);

        return [
            'timestamp' => self::mktimeInTimezone($year, $month, $day, 0, 0, 0, $tzName),
            'microsecond' => 0,
        ];
    }

    /**
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function dayWordMidnightParseResult(string $dayWord, int $base, string $tzName): ?array
    {
        $offset = match ($dayWord) {
            'today' => 0,
            'tomorrow' => 1,
            'yesterday' => -1,
            default => 999,
        };
        if (999 === $offset) {
            return null;
        }
        if (0 !== $offset) {
            try {
                $base = self::modifyRelative($base, ($offset > 0 ? '+' : '').$offset.' day', $tzName);
            } catch (NativeDateMalformedStringException) {
                return null;
            }
        }

        return self::timeOfDayOnBase($base, 0, 0, 0, $tzName);
    }

    /**
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function dayWordWithTimeParseResult(
        string $dayWord,
        string $timePart,
        int $base,
        string $tzName
    ): ?array {
        $clock = self::tryParseClockTime($timePart);
        if (null === $clock) {
            return null;
        }
        $offset = match ($dayWord) {
            'today' => 0,
            'tomorrow' => 1,
            'yesterday' => -1,
            default => 999,
        };
        if (999 === $offset) {
            return null;
        }
        try {
            $dayBase = self::modifyRelative($base, ($offset >= 0 ? '+' : '').$offset.' day', $tzName);
        } catch (NativeDateMalformedStringException) {
            return null;
        }
        $tm = self::localtime($dayBase);
        if (null === $tm) {
            return null;
        }

        return [
            'timestamp' => self::mktimeInTimezone(
                self::tmInt($tm, 'tm_year') + 1900,
                self::tmInt($tm, 'tm_mon') + 1,
                self::tmInt($tm, 'tm_mday'),
                $clock[0],
                $clock[1],
                $clock[2],
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /**
     * @return array{timestamp: int, microsecond: int}
     */
    private static function timeOfDayOnBase(int $base, int $hour, int $minute, int $second, string $tzName): array
    {
        $tm = self::localtime($base);
        if (null === $tm) {
            return ['timestamp' => $base, 'microsecond' => 0];
        }

        return [
            'timestamp' => self::mktimeInTimezone(
                self::tmInt($tm, 'tm_year') + 1900,
                self::tmInt($tm, 'tm_mon') + 1,
                self::tmInt($tm, 'tm_mday'),
                $hour,
                $minute,
                $second,
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /** @return array{0: int, 1: int, 2: int}|null [hour, minute, second] */
    private static function tryParseClockTime(string $time): ?array
    {
        $time = trim($time);
        if (1 === preg_match('/^(\d{1,2})(?::(\d{2})(?::(\d{2}))?)?\s*(am|pm)$/i', $time, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) && '' !== $matches[2] ? (int) $matches[2] : 0;
            $second = isset($matches[3]) && '' !== $matches[3] ? (int) $matches[3] : 0;
            $ampm = strtolower($matches[4]);
            if ($hour < 1 || $hour > 12 || $minute > 59 || $second > 59) {
                return null;
            }
            if ('pm' === $ampm && $hour < 12) {
                $hour += 12;
            }
            if ('am' === $ampm && 12 === $hour) {
                $hour = 0;
            }

            return [$hour, $minute, $second];
        }
        if (1 === preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            $second = isset($matches[3]) && '' !== $matches[3] ? (int) $matches[3] : 0;
            if ($hour > 23 || $minute > 59 || $second > 59) {
                return null;
            }

            return [$hour, $minute, $second];
        }

        return null;
    }

    private static function weekdayInMonth(int $year, int $month, int $targetWday, bool $last): int
    {
        if ($last) {
            for ($day = self::daysInMonth($year, $month); $day >= 1; --$day) {
                $tm = self::localtime(self::mktimeInTimezone($year, $month, $day, 12, 0, 0, 'UTC'));
                if (null !== $tm && self::tmInt($tm, 'tm_wday') === $targetWday) {
                    return $day;
                }
            }

            return 0;
        }
        $max = self::daysInMonth($year, $month);
        for ($day = 1; $day <= $max; ++$day) {
            $tm = self::localtime(self::mktimeInTimezone($year, $month, $day, 12, 0, 0, 'UTC'));
            if (null !== $tm && self::tmInt($tm, 'tm_wday') === $targetWday) {
                return $day;
            }
        }

        return 0;
    }

    /**
     * php-src timelib — compound signed relative modifiers (#15058).
     */
    private static function tryApplyCompoundSignedRelativeDelta(int $base, string $modifier, string $tzName): ?int
    {
        $modifier = trim($modifier);
        if ('' === $modifier) {
            return null;
        }
        $pos = 0;
        $len = \strlen($modifier);
        $timestamp = $base;
        $matched = false;
        $requireSign = true;
        while ($pos < $len) {
            while ($pos < $len && \ctype_space($modifier[$pos])) {
                ++$pos;
            }
            if ($pos >= $len) {
                break;
            }
            $tail = \substr($modifier, $pos);
            // First chunk requires a sign; later chunks may omit it or repeat ± (php-src relative).
            $pattern = $requireSign
                ? '/^([+-])\s*(\d+)\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years|weekday|weekdays)\b/i'
                : '/^([+-])?\s*(\d+)\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years|weekday|weekdays)\b/i';
            if (!preg_match($pattern, $tail, $matches)) {
                return $matched ? $timestamp : null;
            }
            $sign = isset($matches[1]) && '' !== $matches[1] ? $matches[1] : '+';
            $chunk = $sign.' '.($matches[2] ?? '').' '.($matches[3] ?? '');
            $requireSign = false;
            $chunk = preg_replace('/\s+/', ' ', trim($chunk)) ?? trim($chunk);
            $pos += \strlen($matches[0]);
            try {
                $timestamp = self::modifyRelative($timestamp, $chunk, $tzName);
            } catch (NativeDateMalformedStringException) {
                return null;
            }
            $matched = true;
        }

        return $matched ? $timestamp : null;
    }

    /**
     * php-src parse_date.re — first|last day of ±N month(s)|year(s); preserve clock (#23987).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function monthBoundaryOfSignedRelativeParseResult(
        string $which,
        string $amountRaw,
        string $unit,
        int $base,
        string $tzName
    ): ?array {
        $amountRaw = preg_replace('/\s+/', '', $amountRaw) ?? $amountRaw;
        if ('' === $amountRaw || !preg_match('/^[+-]?\d+$/', $amountRaw)) {
            return null;
        }
        $amount = (int) $amountRaw;
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900;
        $month = self::tmInt($tm, 'tm_mon') + 1;
        if (str_starts_with($unit, 'month')) {
            [$year, $month] = self::shiftYearMonth($year, $month, $amount);
        } elseif (str_starts_with($unit, 'year')) {
            $year += $amount;
        } else {
            return null;
        }
        $day = 'first' === $which ? 1 : self::daysInMonth($year, $month);

        return [
            'timestamp' => self::mktimeInTimezone(
                $year,
                $month,
                $day,
                self::tmInt($tm, 'tm_hour'),
                self::tmInt($tm, 'tm_min'),
                self::tmInt($tm, 'tm_sec'),
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src timelib — first/last day of next|this|last month (#14326).
     * Preserves hour/minute/second from $base (php-src does not TIMELIB_UNHAVE_TIME here; #23936).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function monthBoundaryParseResult(string $which, string $when, int $base, string $tzName): ?array
    {
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900;
        $month = self::tmInt($tm, 'tm_mon') + 1;
        $hour = self::tmInt($tm, 'tm_hour');
        $minute = self::tmInt($tm, 'tm_min');
        $second = self::tmInt($tm, 'tm_sec');
        $monthDelta = match ($when) {
            'next' => 1,
            'last' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $monthDelta) {
            return null;
        }
        [$year, $month] = self::shiftYearMonth($year, $month, $monthDelta);
        $day = 'first' === $which ? 1 : self::daysInMonth($year, $month);

        return [
            'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src — first|last day of MonthName next|last|this|previous year (#23936).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function monthBoundaryOfRelativeYearParseResult(
        string $which,
        string $monthName,
        string $when,
        int $base,
        string $tzName
    ): ?array {
        $month = self::englishMonthToNumber($monthName);
        if (null === $month) {
            return null;
        }
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $yearDelta = match ($when) {
            'next' => 1,
            'last', 'previous' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $yearDelta) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900 + $yearDelta;
        $day = 'first' === $which ? 1 : self::daysInMonth($year, $month);

        return [
            'timestamp' => self::mktimeInTimezone(
                $year,
                $month,
                $day,
                self::tmInt($tm, 'tm_hour'),
                self::tmInt($tm, 'tm_min'),
                self::tmInt($tm, 'tm_sec'),
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src — "monday this week" within ISO week (Mon–Sun); snaps to midnight (#23936).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function weekdayOfWeekParseResult(
        string $weekday,
        string $when,
        int $base,
        string $tzName
    ): ?array {
        $weekStart = self::weekOffsetParseResult($when, $base, $tzName);
        if (null === $weekStart) {
            return null;
        }
        $target = self::weekdayNameToNumber($weekday);
        if ($target < 0) {
            return null;
        }
        // Monday of week → +0 … Sunday → +6
        $daysFromMonday = ($target + 6) % 7;
        $timestamp = $weekStart['timestamp'];
        if ($daysFromMonday > 0) {
            try {
                $timestamp = self::modifyRelative($timestamp, '+'.$daysFromMonday.' day', $tzName);
            } catch (NativeDateMalformedStringException) {
                return null;
            }
        }
        $tm = self::localtime($timestamp);
        if (null === $tm) {
            return null;
        }

        return [
            'timestamp' => self::mktimeInTimezone(
                self::tmInt($tm, 'tm_year') + 1900,
                self::tmInt($tm, 'tm_mon') + 1,
                self::tmInt($tm, 'tm_mday'),
                0,
                0,
                0,
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src parse_date.re — back of H → H:15; front of H → (H-1):45 (#23936).
     *
     * Timelib matches `hour24` then continues scanning; a glued ISO/yy date remainder
     * (e.g. `back of 2024-01-15` → hour 20 + `24-01-15`) sets that calendar day (#24395).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function backFrontOfHourParseResult(
        string $which,
        string $hourPart,
        int $base,
        string $tzName
    ): ?array {
        $hourPart = trim($hourPart);
        $clock = self::tryParseHourToken($hourPart);
        $ymd = null;
        if (null === $clock) {
            $split = self::trySplitBackFrontHourAndDate($hourPart);
            if (null === $split) {
                return null;
            }
            $clock = $split[0];
            $ymd = $split[1];
        }
        $hour = $clock;
        $minute = 0;
        if ('back' === $which) {
            $minute = 15;
        } else {
            // front of N → (N-1):45
            $hour = ($hour + 23) % 24;
            $minute = 45;
        }

        if (null !== $ymd) {
            return [
                'timestamp' => self::mktimeInTimezone(
                    $ymd[0],
                    $ymd[1],
                    $ymd[2],
                    $hour,
                    $minute,
                    0,
                    $tzName
                ),
                'microsecond' => 0,
            ];
        }

        return self::timeOfDayOnBase($base, $hour, $minute, 0, $tzName);
    }

    /**
     * Timelib backof|frontof: consume hour24 (+ optional meridian), then a date remainder (#24395).
     *
     * @return array{0: int, 1: array{0: int, 1: int, 2: int}}|null [hour, [Y, m, d]]
     */
    private static function trySplitBackFrontHourAndDate(string $part): ?array
    {
        // Spaced forms: "9 2024-01-15", "9am 2024-01-15", "09 2024-01-15".
        if (1 === preg_match('/^(2[0-4]|[01]?[0-9])\s*(am|pm)?\s+(.+)$/i', $part, $matches)) {
            $hour = self::applyBackFrontMeridian((int) $matches[1], $matches[2] ?? '');
            if (null === $hour) {
                return null;
            }
            $ymd = self::tryParseBackFrontDateRemainder(trim($matches[3]));
            if (null !== $ymd) {
                return [$hour, $ymd];
            }
        }

        // Glued meridian + date: "9am2024-01-15".
        if (1 === preg_match('/^(2[0-4]|[01]?[0-9])\s*(am|pm)(.+)$/i', $part, $matches)) {
            $hour = self::applyBackFrontMeridian((int) $matches[1], $matches[2]);
            if (null === $hour) {
                return null;
            }
            $ymd = self::tryParseBackFrontDateRemainder(trim($matches[3]));
            if (null !== $ymd) {
                return [$hour, $ymd];
            }
        }

        // Glued digits (longest hour24): "2024-01-15" → hour 20 + "24-01-15".
        if (1 === preg_match('/^(2[0-4]|[01][0-9])(.*)$/', $part, $matches)) {
            $rest = $matches[2];
            if ('' !== $rest && 1 !== preg_match('/^\s*(am|pm)/i', $rest)) {
                $ymd = self::tryParseBackFrontDateRemainder(ltrim($rest));
                if (null !== $ymd) {
                    return [(int) $matches[1], $ymd];
                }
            }
        }
        if (1 === preg_match('/^([0-9])(.*)$/', $part, $matches)) {
            $rest = $matches[2];
            if ('' !== $rest && 1 !== preg_match('/^\s*(am|pm)/i', $rest)) {
                $ymd = self::tryParseBackFrontDateRemainder(ltrim($rest));
                if (null !== $ymd) {
                    return [(int) $matches[1], $ymd];
                }
            }
        }

        return null;
    }

    /**
     * Date remainder after hour24 — YYYY-M-D or YY-M-D (timelib 00–69→20xx, 70–99→19xx).
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function tryParseBackFrontDateRemainder(string $rest): ?array
    {
        $rest = trim($rest);
        if ('' === $rest) {
            return null;
        }
        if (1 === preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $rest, $matches)) {
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }

            return [(int) $matches[1], $month, $day];
        }
        if (1 === preg_match('/^(\d{2})-(\d{1,2})-(\d{1,2})$/', $rest, $matches)) {
            $yy = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
                return null;
            }
            $year = $yy <= 69 ? 2000 + $yy : 1900 + $yy;

            return [$year, $month, $day];
        }

        return null;
    }

    private static function applyBackFrontMeridian(int $hour, string $ampm): ?int
    {
        $ampm = strtolower(trim($ampm));
        if ('' === $ampm) {
            if ($hour > 24) {
                return null;
            }

            return $hour;
        }
        if ($hour < 1 || $hour > 12) {
            return null;
        }
        if ('pm' === $ampm && $hour < 12) {
            $hour += 12;
        }
        if ('am' === $ampm && 12 === $hour) {
            $hour = 0;
        }

        return $hour;
    }

    /** Parse hour token for back/front of: "9", "9am", "17", "5pm". */
    private static function tryParseHourToken(string $token): ?int
    {
        $token = trim($token);
        if (1 === preg_match('/^(\d{1,2})\s*(am|pm)$/i', $token, $matches)) {
            return self::applyBackFrontMeridian((int) $matches[1], $matches[2]);
        }
        if (1 === preg_match('/^(\d{1,2})$/', $token, $matches)) {
            $hour = (int) $matches[1];
            if ($hour > 23) {
                return null;
            }

            return $hour;
        }

        return null;
    }

    /**
     * php-src timelib — next|last|this month preserving day/time (#14326).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function monthOffsetParseResult(string $when, int $base, string $tzName): ?array
    {
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900;
        $month = self::tmInt($tm, 'tm_mon') + 1;
        $day = self::tmInt($tm, 'tm_mday');
        $hour = self::tmInt($tm, 'tm_hour');
        $minute = self::tmInt($tm, 'tm_min');
        $second = self::tmInt($tm, 'tm_sec');
        $monthDelta = match ($when) {
            'next' => 1,
            'last' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $monthDelta) {
            return null;
        }
        [$year, $month] = self::shiftYearMonth($year, $month, $monthDelta);

        return [
            'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src parse_date.re — next|last|this week → Monday of target ISO week (#19547).
     *
     * Preserves hour/minute/second from $base (unlike weekday tokens which snap to midnight).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function weekOffsetParseResult(string $when, int $base, string $tzName): ?array
    {
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $wday = self::tmInt($tm, 'tm_wday'); // 0=Sun … 6=Sat
        $daysFromMonday = ($wday + 6) % 7;
        $weekDelta = match ($when) {
            'next' => 1,
            'last' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $weekDelta) {
            return null;
        }
        $dayDelta = -$daysFromMonday + (7 * $weekDelta);
        if (0 === $dayDelta) {
            return ['timestamp' => $base, 'microsecond' => 0];
        }
        $sign = $dayDelta < 0 ? '-' : '+';
        try {
            $timestamp = self::modifyRelative($base, $sign.\abs($dayDelta).' day', $tzName);
        } catch (NativeDateMalformedStringException) {
            return null;
        }

        return ['timestamp' => $timestamp, 'microsecond' => 0];
    }

    /**
     * php-src parse_date.re — last|next|this year preserving calendar date/time (#17586).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function yearOffsetParseResult(string $when, int $base, string $tzName): ?array
    {
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $yearDelta = match ($when) {
            'next' => 1,
            'last' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $yearDelta) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900 + $yearDelta;
        $month = self::tmInt($tm, 'tm_mon') + 1;
        $day = self::tmInt($tm, 'tm_mday');
        $hour = self::tmInt($tm, 'tm_hour');
        $minute = self::tmInt($tm, 'tm_min');
        $second = self::tmInt($tm, 'tm_sec');

        return [
            'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName),
            'microsecond' => 0,
        ];
    }

    /**
     * php-src parse_date.re — "last year January 1" / "March 15 last year" (#17586).
     *
     * @return array{timestamp: int, microsecond: int}|null
     */
    private static function yearRelativeMonthDayParseResult(
        string $when,
        string $datePart,
        int $base,
        string $tzName
    ): ?array {
        $monthDay = self::tryParseEnglishMonthDay($datePart);
        if (null === $monthDay) {
            return null;
        }
        $tm = self::localtime($base);
        if (null === $tm) {
            return null;
        }
        $yearDelta = match ($when) {
            'next' => 1,
            'last' => -1,
            'this' => 0,
            default => 999,
        };
        if (999 === $yearDelta) {
            return null;
        }
        $year = self::tmInt($tm, 'tm_year') + 1900 + $yearDelta;

        return [
            'timestamp' => self::mktimeInTimezone(
                $year,
                $monthDay['month'],
                $monthDay['day'],
                0,
                0,
                0,
                $tzName
            ),
            'microsecond' => 0,
        ];
    }

    /** @return array{month: int, day: int}|null */
    private static function tryParseEnglishMonthDay(string $fragment): ?array
    {
        $fragment = trim($fragment);
        if (1 === preg_match('/^([A-Za-z]+)\s+(\d{1,2})$/', $fragment, $matches)) {
            $month = self::englishMonthToNumber($matches[1]);
            if (null === $month) {
                return null;
            }

            return ['month' => $month, 'day' => (int) $matches[2]];
        }
        if (1 === preg_match('/^(\d{1,2})\s+([A-Za-z]+)$/', $fragment, $matches)) {
            $month = self::englishMonthToNumber($matches[2]);
            if (null === $month) {
                return null;
            }

            return ['month' => $month, 'day' => (int) $matches[1]];
        }

        return null;
    }

    /** @return array{0: int, 1: int} */
    private static function shiftYearMonth(int $year, int $month, int $monthDelta): array
    {
        $month += $monthDelta;
        while ($month < 1) {
            --$year;
            $month += 12;
        }
        while ($month > 12) {
            ++$year;
            $month -= 12;
        }

        return [$year, $month];
    }

    /** php-src timelib relative weekday modifiers — next/last/this/bare (#14151). */
    private static function weekdayRelativeTimestamp(
        string $modifier,
        string $weekday,
        int $base,
        string $tzName
    ): int|false {
        $tm = self::localtime($base);
        if (null === $tm) {
            return false;
        }
        $target = self::weekdayNameToNumber($weekday);
        if ($target < 0) {
            return false;
        }
        $current = self::tmInt($tm, 'tm_wday');
        $days = match ($modifier) {
            'next' => ($forward = ($target - $current + 7) % 7) === 0 ? 7 : $forward,
            'last', 'previous' => -(($backward = ($current - $target + 7) % 7) === 0 ? 7 : $backward),
            'this' => $current <= $target ? ($target - $current) : (7 - $current + $target),
            'bare' => ($target - $current + 7) % 7,
            default => -999,
        };
        if (-999 === $days) {
            return false;
        }
        $sign = $days < 0 ? '-' : '+';
        $abs = \abs($days);
        try {
            return self::modifyRelative($base, $sign.$abs.' day', $tzName);
        } catch (NativeDateMalformedStringException) {
            return false;
        }
    }

    private static function weekdayNameToNumber(string $weekday): int
    {
        return match (strtolower($weekday)) {
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            default => -1,
        };
    }

    private static function nextWeekdayTimestamp(string $weekday, int $base, string $tzName): int|false
    {
        return self::weekdayRelativeTimestamp('next', $weekday, $base, $tzName);
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

    /** Skip ASCII whitespace before a variable-width numeric createFromFormat token. */
    private static function skipFormatWhitespace(string $time, int &$pos, int $timeLen): void
    {
        while ($pos < $timeLen && \ctype_space($time[$pos])) {
            ++$pos;
        }
    }

    /**
     * php-src timelib textual month (`M`) — three-letter English abbreviation (#23231).
     */
    private static function readFormatTextualMonth(string $time, int &$pos, int $timeLen): int|false
    {
        if ($pos + 3 > $timeLen) {
            return false;
        }
        $abbr = \strtolower(\substr($time, $pos, 3));
        $map = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];
        if (!isset($map[$abbr])) {
            return false;
        }
        $pos += 3;

        return $map[$abbr];
    }

    /**
     * php-src timelib — parse timezone from createFromFormat input (#11487).
     */
    private static function readFormatTimezoneIdentifier(string $time, int &$pos, int $timeLen): string|false
    {
        while ($pos < $timeLen && \ctype_space($time[$pos])) {
            ++$pos;
        }
        if ($pos >= $timeLen) {
            return false;
        }
        $start = $pos;
        while ($pos < $timeLen && (bool) preg_match('/[A-Za-z0-9_+\/-]/', $time[$pos])) {
            ++$pos;
        }
        if ($start === $pos) {
            return false;
        }
        $tzId = \substr($time, $start, $pos - $start);
        try {
            return self::validateTimezoneId($tzId);
        } catch (\PHPCompiler\VM\NativeDateInvalidTimeZoneException) {
            return false;
        }
    }

    private static function readFormatTimezoneAbbreviation(string $time, int &$pos, int $timeLen): string|false
    {
        $abbr = self::readFormatTimezoneAbbreviationRaw($time, $pos, $timeLen);
        if (false === $abbr) {
            return false;
        }

        return self::timezoneNameFromAbbr($abbr);
    }

    /** Raw abbreviation text for format token T (before timezone_name_from_abbr resolution). */
    private static function readFormatTimezoneAbbreviationRaw(string $time, int &$pos, int $timeLen): string|false
    {
        while ($pos < $timeLen && \ctype_space($time[$pos])) {
            ++$pos;
        }
        if ($pos >= $timeLen) {
            return false;
        }
        $start = $pos;
        while ($pos < $timeLen && \ctype_alpha($time[$pos])) {
            ++$pos;
        }
        if ($start === $pos) {
            return false;
        }

        return \substr($time, $start, $pos - $start);
    }

    private static function readFormatTimezoneOffset(string $time, int &$pos, int $timeLen, bool $withColon): string|false
    {
        while ($pos < $timeLen && \ctype_space($time[$pos])) {
            ++$pos;
        }
        if ($pos >= $timeLen) {
            return false;
        }
        $pattern = $withColon ? '/^([+-]\d{2}):(\d{2})/' : '/^([+-])(\d{2})(\d{2})/';
        if (!preg_match($pattern, \substr($time, $pos), $matches)) {
            return false;
        }
        $raw = $withColon
            ? $matches[0]
            : $matches[1].$matches[2].':'.$matches[3];
        $canonical = self::canonicalNumericTimezoneId($raw);
        if (null === $canonical) {
            return false;
        }
        $pos += \strlen($matches[0]);

        return $canonical;
    }

    /**
     * php-src date_modify() / timelib_strtotime relative branch (#6132, #14326).
     */
    public static function modifyRelative(int $timestamp, string $modifier, string $tzName): int
    {
        $modifier = trim($modifier);
        if ('' === $modifier) {
            self::throwModifyMalformed($modifier);
        }

        return self::withTimezone($tzName, static function () use ($timestamp, $modifier, $tzName): int {
            $signed = self::tryApplySignedRelativeDelta($timestamp, $modifier, $tzName);
            if (null !== $signed) {
                return $signed;
            }

            $extended = self::tryParseExtendedDateTimeString($modifier, $tzName, $timestamp);
            if (null !== $extended) {
                return $extended['timestamp'];
            }

            self::throwModifyMalformed($modifier);
        });
    }

    /**
     * Signed unit deltas only (+1 day, -2 weeks, …) — fast path for date_modify().
     */
    private static function tryApplySignedRelativeDelta(int $timestamp, string $modifier, string $tzName): ?int
    {
        try {
            $delta = self::parseSignedRelativeDelta($modifier);
        } catch (NativeDateMalformedStringException) {
            return null;
        }

        $tm = self::localtime($timestamp);
        if (null === $tm) {
            return null;
        }
        $year = (self::tmInt($tm, 'tm_year') + 1900);
        $month = (self::tmInt($tm, 'tm_mon') + 1);
        $day = self::tmInt($tm, 'tm_mday');
        $hour = self::tmInt($tm, 'tm_hour');
        $minute = self::tmInt($tm, 'tm_min');
        $second = self::tmInt($tm, 'tm_sec');
        // php-src do_adjust_special_weekday — skip Sat/Sun (#25262).
        if ('weekday' === $delta['unit']) {
            $adjusted = self::specialWeekdayParseResult($delta['amount'], $timestamp, $tzName, true);

            return null === $adjusted ? null : $adjusted['timestamp'];
        }

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
                return null;
        }

        return self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName);
    }

    public static function format(int $timestamp, int $microsecond, string $tzName, string $format): string
    {
        $fixed = self::parseNumericTimezoneOffset($tzName);
        if (null !== $fixed) {
            $tm = self::gmtime($timestamp + $fixed);
            if (null === $tm) {
                return '';
            }

            return VmDate::formatDateTimeFromTm($format, $timestamp, $microsecond, $tm, $fixed, $tzName);
        }

        return self::withTimezone($tzName, static function () use ($timestamp, $microsecond, $format, $tzName): string {
            // Same shape as the fixed-offset branch: civil fields from UTC epoch+offset
            // (#27142). Relying on localtime alone regressed after #26900 made VmDatePure
            // localtime UTC-only without applying the active zone offset.
            $offset = self::offsetSecondsForTimestamp($timestamp);
            $tm = self::gmtime($timestamp + $offset);
            if (null === $tm) {
                return '';
            }

            return VmDate::formatDateTimeFromTm($format, $timestamp, $microsecond, $tm, $offset, $tzName);
        });
    }

    public static function timezoneOffsetSeconds(string $tzName, int $timestamp): int
    {
        $fixed = self::parseNumericTimezoneOffset($tzName);
        if (null !== $fixed) {
            return $fixed;
        }

        return self::withTimezone($tzName, static function () use ($timestamp): int {
            return self::offsetSecondsForTimestamp($timestamp);
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
        $path = self::zoneinfoPath($tzName);
        if (null === $path) {
            return false;
        }
        if ($begin > $end) {
            return false;
        }

        $fixedOffset = self::parseNumericTimezoneOffset($tzName);
        if (null !== $fixedOffset) {
            return [self::buildTransitionRecord($tzName, $begin)];
        }

        $tzifTimes = self::readTzifTransitionTimes($path);
        if (null !== $tzifTimes) {
            return self::transitionsFromTzifTimes($tzName, $begin, $end, $tzifTimes);
        }

        if ($end - $begin > self::TRANSITION_SCAN_MAX_SPAN) {
            return [self::buildTransitionRecord($tzName, $begin)];
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
        $fixedOffset = self::parseNumericTimezoneOffset($tzName);
        if (null !== $fixedOffset) {
            return self::mktimeUtc($year, $month, $day, $hour, $minute, $second) - $fixedOffset;
        }

        return self::withTimezone($tzName, static function () use (
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second
        ): int {
            $result = VmDatePure::mktime($hour, $minute, $second, $month, $day, $year);
            if (false === $result) {
                self::throwMalformedDateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}");
            }

            return $result;
        });
    }

    private static function mktimeUtc(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second
    ): int {
        $result = VmDatePure::gmmktime($hour, $minute, $second, $month, $day, $year);
        if (false === $result) {
            self::throwMalformedDateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}");
        }

        return $result;
    }

    /**
     * @return array{timestamp: int, microsecond: int}
     */
    private static function readNow(): array
    {
        $tv = VmDatePure::readTimeval();

        return ['timestamp' => $tv['sec'], 'microsecond' => $tv['usec']];
    }

    /**
     * php-src timelib have_time — any H/G/i/s/u/U token in the format string (#16383).
     */
    private static function formatStringHasTimeTokens(string $format): bool
    {
        if (\str_starts_with($format, '!')) {
            $format = \substr($format, 1);
        }
        $len = \strlen($format);
        for ($i = 0; $i < $len; ++$i) {
            if ('\\' === $format[$i]) {
                if ($i + 1 < $len) {
                    ++$i;
                }

                continue;
            }
            if (\str_contains('HGiisuU', $format[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}|null
     */
    private static function localtime(int $timestamp): ?array
    {
        return VmDatePure::localtime($timestamp);
    }

    /**
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}|null
     */
    private static function gmtime(int $timestamp): ?array
    {
        return VmDatePure::gmtime($timestamp);
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
     * Parse zone.tab ISO 6709 coordinates (±DDMM±DDDMM or ±DDMMSS±DDDMMSS).
     *
     * php-src/timelib zone.tab rows use seconds when needed for accuracy
     * (e.g. America/New_York `+404251-0740023`). Matching only the minute form
     * zeroes latitude/longitude while country_code/comments still parse (#22291).
     *
     * Bundled timezonedb stores coords as uint32 packed at 1e5 scale
     * (php-src ext/date/lib/parse_tz.c read_location):
     *   latitude  = packed / 100000 - 90
     *   longitude = packed / 100000 - 180
     * The generator truncates the ISO-6709 degree value toward zero at 5 decimals
     * before packing, so the observable Zend float is
     *   (int)($deg * 100000) / 100000.0
     * which json_encode bit-matches php-src (#30953).
     *
     * @return array{latitude: float, longitude: float}
     */
    private static function parseZoneTabCoordinates(string $coords): array
    {
        if (!preg_match(
            '/^([+-])(\d{2})(\d{2})(\d{2})?([+-])(\d{3})(\d{2})(\d{2})?$/',
            $coords,
            $matches
        )) {
            return ['latitude' => 0.0, 'longitude' => 0.0];
        }
        $latSign = '+' === $matches[1] ? 1 : -1;
        $latSec = isset($matches[4]) && '' !== $matches[4] ? (int) $matches[4] : 0;
        $lat = $latSign * ((int) $matches[2] + ((int) $matches[3]) / 60.0 + $latSec / 3600.0);
        $lonSign = '+' === $matches[5] ? 1 : -1;
        $lonSec = isset($matches[8]) && '' !== $matches[8] ? (int) $matches[8] : 0;
        $lon = $lonSign * ((int) $matches[6] + ((int) $matches[7]) / 60.0 + $lonSec / 3600.0);

        return [
            'latitude' => self::quantizeTimelibGeoCoord($lat),
            'longitude' => self::quantizeTimelibGeoCoord($lon),
        ];
    }

    /**
     * Toward-zero 5-decimal quantisation matching timelib location packing (#30953).
     *
     * php-src: ext/date/lib/parse_tz.c read_location — packed uint32 / 100000.
     */
    private static function quantizeTimelibGeoCoord(float $degrees): float
    {
        return ((int) ($degrees * 100000.0)) / 100000.0;
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
    /**
     * @param list<int> $times sorted TZif transition timestamps
     *
     * @return list<array{ts: int, time: string, offset: int, isdst: bool, abbr: string}>
     */
    private static function transitionsFromTzifTimes(
        string $tzName,
        int $begin,
        int $end,
        array $times
    ): array {
        $transitions = [self::buildTransitionRecord($tzName, $begin)];
        foreach ($times as $ts) {
            if ($ts <= $begin) {
                continue;
            }
            if ($ts > $end) {
                break;
            }
            if (($transitions[\count($transitions) - 1]['ts'] ?? null) !== $ts) {
                $transitions[] = self::buildTransitionRecord($tzName, $ts);
            }
        }

        return $transitions;
    }

    /**
     * @return list<int>|null
     */
    private static function readTzifTransitionTimes(string $path): ?array
    {
        $data = VmFs::fileGetContents($path);
        if (!\is_string($data) || \strlen($data) < 44 || !\str_starts_with($data, 'TZif')) {
            return null;
        }
        $pos = \strrpos($data, 'TZif');
        if (false === $pos) {
            return null;
        }

        return self::parseTzifBlockTransitionTimes($data, $pos);
    }

    /**
     * @return list<int>|null
     */
    private static function parseTzifBlockTransitionTimes(string $data, int $pos): ?array
    {
        if ('TZif' !== \substr($data, $pos, 4)) {
            return null;
        }
        $version = $data[$pos + 4];
        $header = \unpack('Ntisut/Ntisgmt/Nleap/Ntime/Ntype/Nchar', \substr($data, $pos + 20, 24));
        if (!\is_array($header)) {
            return null;
        }
        $timecnt = (int) $header['time'];
        if ($timecnt < 0) {
            return null;
        }
        $offset = $pos + 44;
        $timeSize = ($version >= '2') ? 8 : 4;
        if ($offset + ($timecnt * $timeSize) > \strlen($data)) {
            return null;
        }
        $times = [];
        for ($i = 0; $i < $timecnt; ++$i) {
            $times[] = 8 === $timeSize
                ? self::readInt64BE($data, $offset + ($i * 8))
                : self::readInt32BE($data, $offset + ($i * 4));
        }

        return $times;
    }

    private static function readInt32BE(string $data, int $off): int
    {
        $unpacked = \unpack('N', \substr($data, $off, 4));
        if (!\is_array($unpacked)) {
            return 0;
        }
        $u = (int) $unpacked[1];
        if ($u > 0x7FFFFFFF) {
            return $u - 0x100000000;
        }

        return $u;
    }

    private static function readInt64BE(string $data, int $off): int
    {
        $parts = \unpack('N2', \substr($data, $off, 8));
        if (!\is_array($parts)) {
            return 0;
        }
        $hi = (int) $parts[1];
        $lo = (int) $parts[2];
        if ($hi > 0x7FFFFFFF) {
            $hi -= 0x100000000;
        }

        return (int) ($hi * 4294967296 + $lo);
    }

    private static function transitionState(string $tzName, int $timestamp): array
    {
        $fixed = self::parseNumericTimezoneOffset($tzName);
        if (null !== $fixed) {
            return ['offset' => $fixed, 'isdst' => false];
        }

        return self::withTimezone($tzName, static function () use ($timestamp): array {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                return ['offset' => 0, 'isdst' => false];
            }
            $isdst = self::tmInt($tm, 'tm_isdst') > 0;

            return ['offset' => self::offsetSecondsForTimestamp($timestamp), 'isdst' => $isdst];
        });
    }

    /**
     * @return array{ts: int, time: string, offset: int, isdst: bool, abbr: string}
     */
    private static function buildTransitionRecord(string $tzName, int $timestamp): array
    {
        $state = self::transitionState($tzName, $timestamp);
        $isdst = $state['isdst'];
        if (!$isdst) {
            // VmDatePure::localtime tm_isdst is unreliable at DST boundaries; infer from standard offset (#16291).
            $standardOffset = self::standardOffsetForTimezone($tzName, $timestamp);
            if ($state['offset'] !== $standardOffset) {
                $isdst = $state['offset'] > $standardOffset;
            }
        }

        return [
            'ts' => $timestamp,
            'time' => self::format($timestamp, 0, $tzName, 'c'),
            'offset' => $state['offset'],
            'isdst' => $isdst,
            'abbr' => self::timezoneAbbreviation($tzName, $timestamp),
        ];
    }

    /** php-src timelib ttinfo tt_isdst — winter reference offset for DST inference (#16291). */
    private static function standardOffsetForTimezone(string $tzName, int $timestamp): int
    {
        $fixed = self::parseNumericTimezoneOffset($tzName);
        if (null !== $fixed) {
            return $fixed;
        }

        return self::withTimezone($tzName, static function () use ($timestamp): int {
            $year = (int) \date('Y', $timestamp);
            $winter = VmDatePure::mktime(12, 0, 0, 1, 15, $year);
            if (false === $winter) {
                return self::offsetSecondsForTimestamp($timestamp);
            }

            return self::offsetSecondsForTimestamp($winter);
        });
    }

    private static function timezoneAbbreviation(string $tzName, int $timestamp): string
    {
        return self::withTimezone($tzName, static function () use ($timestamp): string {
            return VmDatePure::strftime('%Z', $timestamp, false);
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
            $base = \basename($path);
            // Skip dotted metadata files, but keep tzdata.zi which Zend lists under ALL_WITH_BC (#25085).
            if (str_contains($base, '.') && 'tzdata.zi' !== $base && 'posixrules' !== $base) {
                continue;
            }
            $id = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($path, $rootLen));
            if (isset($known[$id])) {
                continue;
            }
            // Packaging trees only — Zend ALL_WITH_BC still lists Factory/localtime/tzdata.zi (#25085).
            if (
                str_starts_with($id, 'posix/')
                || str_starts_with($id, 'right/')
                || 'posixrules' === $id
                || str_ends_with($id, '/posixrules')
            ) {
                continue;
            }
            // Include legacy aliases (Brazil/*, CET, Etc/GMT*, Factory, …) beyond zone.tab (#25085).
            // Prior code filtered GROUP_ALL prefixes and required symlink-or-topline, under-counting vs Zend.
            $ids[] = $id;
            $known[$id] = true;
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
        if (0 === self::$withTimezoneDepth) {
            self::$withTimezoneSavedVmEnvTz = VmEnv::getenv('TZ');
            self::$withTimezoneSavedHostTz = VmDatePure::pushProcessTimezone($tzName);
        }
        ++self::$withTimezoneDepth;
        VmEnv::putenv('TZ='.$tzName);
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
                VmDatePure::popProcessTimezone((string) self::$withTimezoneSavedHostTz);
                self::$withTimezoneSavedHostTz = null;
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
     * php-src date_object_set_date — replace Y-M-D, preserve time of day (#12469).
     *
     * @return array{timestamp: int, microsecond: int}
     */
    public static function replaceDateComponents(
        int $timestamp,
        int $microsecond,
        string $tzName,
        int $year,
        int $month,
        int $day
    ): array {
        return self::withTimezone($tzName, static function () use (
            $timestamp,
            $microsecond,
            $tzName,
            $year,
            $month,
            $day
        ): array {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                throw new \LogicException('Invalid timestamp for DateTime::setDate()');
            }
            $hour = self::tmInt($tm, 'tm_hour');
            $minute = self::tmInt($tm, 'tm_min');
            $second = self::tmInt($tm, 'tm_sec');

            return [
                'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName),
                'microsecond' => $microsecond,
            ];
        });
    }

    /**
     * php-src php_date_isodate_set / timelib_date_from_isodate — ISO week-year → Y-M-D (#19847).
     *
     * @return array{timestamp: int, microsecond: int}
     */
    public static function replaceISODateComponents(
        int $timestamp,
        int $microsecond,
        string $tzName,
        int $year,
        int $week,
        int $dayOfWeek
    ): array {
        [$y, $m, $d] = self::ymdFromIsoDate($year, $week, $dayOfWeek);

        return self::replaceDateComponents($timestamp, $microsecond, $tzName, $y, $m, $d);
    }

    /**
     * php-src timelib_date_from_isodate (ext/date/lib/dow.c).
     *
     * @return array{0: int, 1: int, 2: int} year, month, day
     */
    public static function ymdFromIsoDate(int $isoYear, int $isoWeek, int $isoDay): array
    {
        $daynr = self::daynrFromWeeknr($isoYear, $isoWeek, $isoDay) + 1;
        $y = $isoYear;
        $isLeap = self::isLeapYearGregorian($y);

        while ($daynr <= 0) {
            --$y;
            $isLeap = self::isLeapYearGregorian($y);
            $daynr += $isLeap ? 366 : 365;
        }

        while ($daynr > ($isLeap ? 366 : 365)) {
            $daynr -= $isLeap ? 366 : 365;
            ++$y;
            $isLeap = self::isLeapYearGregorian($y);
        }

        $table = $isLeap
            ? [0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
            : [0, 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $m = 1;
        while ($daynr > $table[$m]) {
            $daynr -= $table[$m];
            ++$m;
        }

        return [$y, $m, $daynr];
    }

    /**
     * php-src timelib_daynr_from_weeknr (ext/date/lib/dow.c).
     */
    private static function daynrFromWeeknr(int $isoYear, int $isoWeek, int $isoDay): int
    {
        $dow = self::timelibDayOfWeek($isoYear, 1, 1);
        $day = 0 - ($dow > 4 ? $dow - 7 : $dow);

        return $day + (($isoWeek - 1) * 7) + $isoDay;
    }

    /**
     * php-src timelib_day_of_week — Sunday=0 … Saturday=6 (ext/date/lib/dow.c).
     */
    private static function timelibDayOfWeek(int $year, int $month, int $day): int
    {
        // m_table_common / m_table_leap from timelib dow.c (1 = January).
        static $mTableCommon = [-1, 0, 3, 3, 6, 1, 4, 6, 2, 5, 0, 3, 5];
        static $mTableLeap = [-1, 6, 2, 3, 6, 1, 4, 6, 2, 5, 0, 3, 5];

        $c1 = self::timelibCenturyValue(\intdiv(self::positiveMod($year, 400), 100));
        $y1 = self::positiveMod($year, 100);
        $m1 = self::isLeapYearGregorian($year) ? $mTableLeap[$month] : $mTableCommon[$month];

        return self::positiveMod(($c1 + $y1 + $m1 + \intdiv($y1, 4) + $day), 7);
    }

    private static function timelibCenturyValue(int $j): int
    {
        return 6 - self::positiveMod($j, 4) * 2;
    }

    private static function positiveMod(int $x, int $y): int
    {
        $tmp = $x % $y;
        if ($tmp < 0) {
            $tmp += $y;
        }

        return $tmp;
    }

    /** Gregorian leap year (timelib_is_leap). */
    private static function isLeapYearGregorian(int $year): bool
    {
        return 0 === ($year % 4) && (0 !== ($year % 100) || 0 === ($year % 400));
    }

    /**
     * php-src date_object_set_time — replace H:i:s.u, preserve calendar date (#12469).
     *
     * @return array{timestamp: int, microsecond: int}
     */
    public static function replaceTimeComponents(
        int $timestamp,
        int $microsecond,
        string $tzName,
        int $hour,
        int $minute,
        int $second,
        int $microsecondNew
    ): array {
        return self::withTimezone($tzName, static function () use (
            $timestamp,
            $tzName,
            $hour,
            $minute,
            $second,
            $microsecondNew
        ): array {
            $tm = self::localtime($timestamp);
            if (null === $tm) {
                throw new \LogicException('Invalid timestamp for DateTime::setTime()');
            }
            $year = (self::tmInt($tm, 'tm_year') + 1900);
            $month = (self::tmInt($tm, 'tm_mon') + 1);
            $day = self::tmInt($tm, 'tm_mday');

            return [
                'timestamp' => self::mktimeInTimezone($year, $month, $day, $hour, $minute, $second, $tzName),
                'microsecond' => $microsecondNew,
            ];
        });
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
            $year = (self::tmInt($tm, 'tm_year') + 1900);
            $month = (self::tmInt($tm, 'tm_mon') + 1);
            $day = self::tmInt($tm, 'tm_mday');
            $hour = self::tmInt($tm, 'tm_hour');
            $minute = self::tmInt($tm, 'tm_min');
            $second = self::tmInt($tm, 'tm_sec');

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
     * Calendar diff between two timestamps (php-src php_date_diff / timelib_diff, #4604, #26693).
     *
     * Microseconds participate in ordering, invert, and DateInterval::$f (fractional seconds).
     *
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: int}
     */
    public static function diffTimestamps(
        int $baseTs,
        int $targetTs,
        string $tzName,
        bool $absolute = false,
        int $baseUs = 0,
        int $targetUs = 0
    ): array {
        $baseUs = self::normalizeMicrosecond($baseUs);
        $targetUs = self::normalizeMicrosecond($targetUs);
        $targetEarlier = $targetTs < $baseTs
            || ($targetTs === $baseTs && $targetUs < $baseUs);
        $invert = $targetEarlier ? 1 : 0;
        $earlier = $invert ? $targetTs : $baseTs;
        $later = $invert ? $baseTs : $targetTs;
        $earlierUs = $invert ? $targetUs : $baseUs;
        $laterUs = $invert ? $baseUs : $targetUs;
        if ($absolute) {
            $invert = 0;
        }
        $days = (int) \floor(\abs($targetTs - $baseTs) / 86_400);

        return self::withTimezone($tzName, static function () use (
            $earlier,
            $later,
            $earlierUs,
            $laterUs,
            $invert,
            $days
        ): array {
            $tm1 = self::localtime($earlier);
            $tm2 = self::localtime($later);
            if (null === $tm1 || null === $tm2) {
                throw new \LogicException('Invalid timestamp for date_diff()');
            }

            $y1 = self::tmInt($tm1, 'tm_year') + 1900;
            $m1 = self::tmInt($tm1, 'tm_mon') + 1;
            $d1 = self::tmInt($tm1, 'tm_mday');
            $h1 = self::tmInt($tm1, 'tm_hour');
            $i1 = self::tmInt($tm1, 'tm_min');
            $s1 = self::tmInt($tm1, 'tm_sec');

            $y2 = self::tmInt($tm2, 'tm_year') + 1900;
            $m2 = self::tmInt($tm2, 'tm_mon') + 1;
            $d2 = self::tmInt($tm2, 'tm_mday');
            $h2 = self::tmInt($tm2, 'tm_hour');
            $i2 = self::tmInt($tm2, 'tm_min');
            $s2 = self::tmInt($tm2, 'tm_sec');

            // Fractional seconds first (timelib relative us → DateInterval::$f).
            $f = ($laterUs - $earlierUs) / 1_000_000.0;
            $s = $s2 - $s1;
            $i = $i2 - $i1;
            $h = $h2 - $h1;
            $d = $d2 - $d1;
            $m = $m2 - $m1;
            $y = $y2 - $y1;

            if ($f < 0.0) {
                $f += 1.0;
                --$s;
            }
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
            // timelib_do_rel_normalize — keep borrowing months until d >= 0 (#22849).
            // One-shot borrow left month-end → next-month-1st with negative d (m=1,d=-1).
            $monthCursor = $m2;
            $yearCursor = $y2;
            while ($d < 0) {
                --$monthCursor;
                if ($monthCursor < 1) {
                    $monthCursor = 12;
                    --$yearCursor;
                }
                $d += self::daysInMonth($yearCursor, $monthCursor);
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
                'f' => $f,
                'invert' => $invert,
                'days' => $days,
            ];
        });
    }

    private static function normalizeMicrosecond(int $us): int
    {
        if ($us < 0) {
            return 0;
        }
        if ($us > 999_999) {
            return 999_999;
        }

        return $us;
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
            '/^([+-])\s*(\d+)\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years|weekday|weekdays)$/i',
            $modifier,
            $matches
        )) {
            self::throwModifyMalformed($modifier);
        }
        $sign = '-' === $matches[1] ? -1 : 1;
        $amount = $sign * (int) $matches[2];
        $unit = strtolower($matches[3]);
        if ('weekdays' === $unit) {
            $unit = 'weekday';
        } elseif (str_ends_with($unit, 's')) {
            $unit = substr($unit, 0, -1);
        }

        return ['amount' => $amount, 'unit' => $unit];
    }

    /**
     * @param array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int} $tm
     */
    private static function tmInt(array $tm, string $field): int
    {
        return (int) ($tm[$field] ?? 0);
    }

    private static function offsetSecondsForTimestamp(int $timestamp): int
    {
        if (!\function_exists('date')) {
            return 0;
        }

        return (int) \date('Z', $timestamp);
    }
}
