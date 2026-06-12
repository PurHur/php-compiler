<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\DateTimeZoneSupport;

/**
 * Native DateTime/DateTimeZone semantics without host Zend \\DateTime (issue #6164).
 * TZ switching via {@see VmEnv} libc FFI — no host Zend env builtins (#8086).
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
        if ('now' === strtolower($time)) {
            return self::readNow();
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
     * @return array{timestamp: int, microsecond: int}|false
     */
    public static function parseFromFormat(string $format, string $time, string $tzName): array|false
    {
        if ('U.u' === $format) {
            if (1 !== preg_match('/^(\d+)\.(\d+)$/', $time, $matches)) {
                return false;
            }

            return [
                'timestamp' => (int) $matches[1],
                'microsecond' => (int) \str_pad(\substr($matches[2], 0, 6), 6, '0', STR_PAD_RIGHT),
            ];
        }

        return false;
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

            $year = (int) $tm->tm_year + 1900;
            $month = (int) $tm->tm_mon + 1;
            $day = (int) $tm->tm_mday;
            $hour = (int) $tm->tm_hour;
            $minute = (int) $tm->tm_min;
            $second = (int) $tm->tm_sec;
            $offset = self::timezoneOffsetSeconds($tzName, $timestamp);

            $out = '';
            $len = \strlen($format);
            for ($i = 0; $i < $len; ++$i) {
                $ch = $format[$i];
                if ('\\' === $ch && $i + 1 < $len) {
                    $out .= $format[++$i];

                    continue;
                }
                switch ($ch) {
                    case 'Y':
                        $out .= self::padInt($year, 4);

                        break;
                    case 'm':
                        $out .= self::padInt($month, 2);

                        break;
                    case 'd':
                        $out .= self::padInt($day, 2);

                        break;
                    case 'H':
                        $out .= self::padInt($hour, 2);

                        break;
                    case 'i':
                        $out .= self::padInt($minute, 2);

                        break;
                    case 's':
                        $out .= self::padInt($second, 2);

                        break;
                    case 'u':
                        $out .= self::padInt($microsecond, 6);

                        break;
                    case 'c':
                        $out .= self::padInt($year, 4).'-'
                            .self::padInt($month, 2).'-'
                            .self::padInt($day, 2).'T'
                            .self::padInt($hour, 2).':'
                            .self::padInt($minute, 2).':'
                            .self::padInt($second, 2)
                            .self::formatOffset($offset);

                        break;
                    default:
                        $out .= $ch;
                }
                if (\strlen($out) >= self::FORMAT_OUT_BYTES) {
                    break;
                }
            }

            return $out;
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
        $lines = @\file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
        $previous = VmEnv::getenv('TZ');
        $ffi = self::ffi();
        VmEnv::putenv('TZ='.$tzName);
        if (null !== $ffi) {
            $ffi->tzset();
        }
        try {
            return $fn();
        } finally {
            if (false === $previous || '' === $previous) {
                VmEnv::putenv('TZ');
            } else {
                VmEnv::putenv('TZ='.$previous);
            }
            if (null !== $ffi) {
                $ffi->tzset();
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
