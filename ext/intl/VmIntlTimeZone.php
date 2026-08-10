<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ClassConstName;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\ext\standard\VmDateTimeNative;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\DateTimeZoneSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlTimeZone — Gregorian/zoneinfo subset without full ICU (#6151).
 *
 * php-src: ext/intl/timezone/timezone_class.c, timezone_methods.c, timezone.stub.php
 */
final class VmIntlTimeZone
{
    public const CLASS_LC = 'intltimezone';

    public const DISPLAY_SHORT = 1;
    public const DISPLAY_LONG = 2;
    public const DISPLAY_SHORT_GENERIC = 3;
    public const DISPLAY_LONG_GENERIC = 4;
    public const DISPLAY_SHORT_GMT = 5;
    public const DISPLAY_LONG_GMT = 6;
    public const DISPLAY_SHORT_COMMONLY_USED = 7;
    public const DISPLAY_GENERIC_LOCATION = 8;

    public const TYPE_ANY = 0;
    public const TYPE_CANONICAL = 1;
    public const TYPE_CANONICAL_LOCATION = 2;

    /** @var array<int, array{id: string}> */
    private static array $state = [];

    /**
     * Zoneinfo realpath → sorted equivalent IDs (php-src TimeZone::countEquivalentIDs; #20824).
     *
     * @var array<string, list<string>>|null
     */
    private static ?array $equivalentByReal = null;

    /** @var array<string, list<string>>|null id → sorted equivalents */
    private static ?array $equivalentById = null;

    /** @var array<string, string>|null Olson → Windows (reverse of WINDOWS_TO_OLSON) */
    private static ?array $olsonToWindows = null;

    private const ZONEINFO_ROOT = '/usr/share/zoneinfo';

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'DISPLAY_SHORT' => self::DISPLAY_SHORT,
            'DISPLAY_LONG' => self::DISPLAY_LONG,
            'DISPLAY_SHORT_GENERIC' => self::DISPLAY_SHORT_GENERIC,
            'DISPLAY_LONG_GENERIC' => self::DISPLAY_LONG_GENERIC,
            'DISPLAY_SHORT_GMT' => self::DISPLAY_SHORT_GMT,
            'DISPLAY_LONG_GMT' => self::DISPLAY_LONG_GMT,
            'DISPLAY_SHORT_COMMONLY_USED' => self::DISPLAY_SHORT_COMMONLY_USED,
            'DISPLAY_GENERIC_LOCATION' => self::DISPLAY_GENERIC_LOCATION,
            'TYPE_ANY' => self::TYPE_ANY,
            'TYPE_CANONICAL' => self::TYPE_CANONICAL,
            'TYPE_CANONICAL_LOCATION' => self::TYPE_CANONICAL_LOCATION,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlTimeZone');
        $entry->isInternal = true;
        // Exact Zend casing for defined()/hasConstant after #25910 (#29999 / #28132).
        foreach (self::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $methods = [
            'createtimezone' => [new IntlTimeZoneCreateTimeZone(), 'createTimeZone', $pubStatic],
            'createdefault' => [new IntlTimeZoneCreateDefault(), 'createDefault', $pubStatic],
            'fromdatetimezone' => [new IntlTimeZoneFromDateTimeZone(), 'fromDateTimeZone', $pubStatic],
            'getcanonicalid' => [new IntlTimeZoneGetCanonicalID(), 'getCanonicalID', $pubStatic],
            'getregion' => [new IntlTimeZoneGetRegion(), 'getRegion', $pubStatic],
            'getgmt' => [new IntlTimeZoneGetGMT(), 'getGMT', $pubStatic],
            'getunknown' => [new IntlTimeZoneGetUnknown(), 'getUnknown', $pubStatic],
            'createenumeration' => [new IntlTimeZoneCreateEnumeration(), 'createEnumeration', $pubStatic],
            'createtimezoneidenumeration' => [new IntlTimeZoneCreateTimeZoneIDEnumeration(), 'createTimeZoneIDEnumeration', $pubStatic],
            'getidforwindowsid' => [new IntlTimeZoneGetIDForWindowsID(), 'getIDForWindowsID', $pubStatic],
            'getwindowsid' => [new IntlTimeZoneGetWindowsID(), 'getWindowsID', $pubStatic],
            'countequivalentids' => [new IntlTimeZoneCountEquivalentIDs(), 'countEquivalentIDs', $pubStatic],
            'getequivalentid' => [new IntlTimeZoneGetEquivalentID(), 'getEquivalentID', $pubStatic],
            'gettzdataversion' => [new IntlTimeZoneGetTZDataVersion(), 'getTZDataVersion', $pubStatic],
            'geterrorcode' => [new IntlTimeZoneGetErrorCode(), 'getErrorCode', $pub],
            'geterrormessage' => [new IntlTimeZoneGetErrorMessage(), 'getErrorMessage', $pub],
            'getid' => [new IntlTimeZoneGetID(), 'getID', $pub],
            'getrawoffset' => [new IntlTimeZoneGetRawOffset(), 'getRawOffset', $pub],
            'getdstsavings' => [new IntlTimeZoneGetDSTSavings(), 'getDSTSavings', $pub],
            'usedaylighttime' => [new IntlTimeZoneUseDaylightTime(), 'useDaylightTime', $pub],
            'getdisplayname' => [new IntlTimeZoneGetDisplayName(), 'getDisplayName', $pub],
            'getoffset' => [new IntlTimeZoneGetOffset(), 'getOffset', $pub],
            'todatetimezone' => [new IntlTimeZoneToDateTimeZone(), 'toDateTimeZone', $pub],
            'hassamerules' => [new IntlTimeZoneHasSameRules(), 'hasSameRules', $pub],
        ];
        // php-src has getGMT/getUnknown only — getUTC never existed (#26745)
        if (IntlExtensionPolicy::advertisesIntlTimeZoneGetUtc()) {
            $methods['getutc'] = [new IntlTimeZoneGetUTC(), 'getUTC', $pubStatic];
        }
        // php-src timezone.stub.php — getIanaID only when U_ICU_VERSION_MAJOR_NUM >= 74 (#20926).
        if (IntlExtensionPolicy::advertisesIanaTimeZoneId()) {
            $methods['getianaid'] = [new IntlTimeZoneGetIanaID(), 'getIanaID', $pubStatic];
        }
        foreach ($methods as $lc => [$handler, $name, $vis]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isTimeZoneObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function idOf(ObjectEntry $object): string
    {
        return self::$state[$object->id]['id'] ?? 'UTC';
    }

    /**
     * Windows timezone ID → Olson (subset; #20852). Region filter ignored in v1.
     *
     * @var array<string, string>
     */
    private const WINDOWS_TO_OLSON = [
        'UTC' => 'UTC',
        'GMT Standard Time' => 'Europe/London',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik',
        'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Alaskan Standard Time' => 'America/Anchorage',
        'Hawaiian Standard Time' => 'Pacific/Honolulu',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Romance Standard Time' => 'Europe/Paris',
        'GTB Standard Time' => 'Europe/Bucharest',
        'FLE Standard Time' => 'Europe/Helsinki',
        'Russian Standard Time' => 'Europe/Moscow',
        'Tokyo Standard Time' => 'Asia/Tokyo',
        'China Standard Time' => 'Asia/Shanghai',
        'Singapore Standard Time' => 'Asia/Singapore',
        'Korea Standard Time' => 'Asia/Seoul',
        'India Standard Time' => 'Asia/Kolkata',
        'AUS Eastern Standard Time' => 'Australia/Sydney',
        'New Zealand Standard Time' => 'Pacific/Auckland',
    ];

    /** @return list<string> */
    public static function listAvailableIds(): array
    {
        return VmDateTimeNative::timezoneIdentifiersList(DateTimeZoneSupport::GROUP_ALL, null);
    }

    public static function createEnumeration(Context $ctx, ?string $countryOrZoneId = null): ObjectEntry
    {
        $ids = self::listAvailableIds();
        if (null !== $countryOrZoneId && '' !== $countryOrZoneId) {
            $needle = $countryOrZoneId;
            $filtered = [];
            if (2 === \strlen($needle)) {
                $cc = strtoupper($needle);
                foreach ($ids as $id) {
                    $region = self::regionOfId($id);
                    if ($region === $cc) {
                        $filtered[] = $id;
                    }
                }
            } else {
                foreach ($ids as $id) {
                    if ($id === $needle || str_starts_with($id, $needle.'/')) {
                        $filtered[] = $id;
                    }
                }
            }
            $ids = $filtered;
        }
        IntlError::clear();

        return VmIntlIterator::fromStringList($ctx, $ids);
    }

    public static function createTimeZoneIDEnumeration(
        Context $ctx,
        int $zoneType,
        ?string $region,
        ?int $rawOffset
    ): ObjectEntry {
        $ids = self::listAvailableIds();
        if (null !== $region && '' !== $region) {
            $cc = strtoupper($region);
            $ids = array_values(array_filter(
                $ids,
                static fn (string $id): bool => self::regionOfId($id) === $cc
            ));
        }
        if (self::TYPE_CANONICAL === $zoneType || self::TYPE_CANONICAL_LOCATION === $zoneType) {
            // Drop POSIX-style aliases with only Etc/ when canonical-location preferred.
            $ids = array_values(array_filter(
                $ids,
                static fn (string $id): bool => !str_starts_with($id, 'Etc/') || 'Etc/UTC' === $id || 'Etc/GMT' === $id
            ));
        }
        if (null !== $rawOffset) {
            $ids = array_values(array_filter(
                $ids,
                static function (string $id) use ($rawOffset): bool {
                    try {
                        return VmDateTimeNative::timezoneOffsetSeconds($id, VmDate::time()) * 1000 === $rawOffset;
                    } catch (\Throwable) {
                        return false;
                    }
                }
            ));
        }
        IntlError::clear();

        return VmIntlIterator::fromStringList($ctx, $ids);
    }

    private static function regionOfId(string $id): string
    {
        $region = self::getRegion($id);

        return false === $region ? '' : $region;
    }

    public static function getIDForWindowsID(string $windowsId, ?string $region): string|false
    {
        unset($region);
        foreach (self::WINDOWS_TO_OLSON as $win => $olson) {
            if (0 === strcasecmp($win, $windowsId)) {
                IntlError::clear();

                return $olson;
            }
        }
        IntlError::set(
            IntlError::U_ILLEGAL_ARGUMENT_ERROR,
            'intltz_get_id_for_windows_id: unknown Windows ID: U_ILLEGAL_ARGUMENT_ERROR'
        );

        return false;
    }

    /**
     * php-src intltz_get_windows_id — reverse of {@see getIDForWindowsID} (#20824).
     * Alias IDs resolve via zoneinfo equivalence (subset map).
     */
    public static function getWindowsID(string $timezoneId): string|false
    {
        $equiv = self::equivalentIds($timezoneId);
        if ([] === $equiv) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intltz_get_windows_id: No such time zone: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        self::ensureOlsonToWindows();
        foreach ($equiv as $id) {
            if (isset(self::$olsonToWindows[$id])) {
                IntlError::clear();

                return self::$olsonToWindows[$id];
            }
        }
        IntlError::set(
            IntlError::U_ILLEGAL_ARGUMENT_ERROR,
            'intltz_get_windows_id: no Windows ID for zone: U_ILLEGAL_ARGUMENT_ERROR'
        );

        return false;
    }

    /** php-src intltz_count_equivalent_ids — zoneinfo symlink clique (#20824). */
    public static function countEquivalentIDs(string $timezoneId): int
    {
        $ids = self::equivalentIds($timezoneId);
        IntlError::clear();

        return \count($ids);
    }

    /**
     * php-src intltz_get_equivalent_id — empty string when out of range (#20824).
     */
    public static function getEquivalentID(string $timezoneId, int $index): string
    {
        $ids = self::equivalentIds($timezoneId);
        IntlError::clear();
        if ($index < 0 || $index >= \count($ids)) {
            return '';
        }

        return $ids[$index];
    }

    /**
     * php-src intltz_get_tz_data_version — Olson/tzdata version string (#20824).
     * Reads `# version YYYYx` from zoneinfo tzdata.zi when present (ICU shape).
     */
    public static function getTZDataVersion(): string
    {
        $zi = self::ZONEINFO_ROOT.'/tzdata.zi';
        if (\is_file($zi)) {
            $fh = \fopen($zi, 'rb');
            if (false !== $fh) {
                $line = \fgets($fh);
                \fclose($fh);
                if (\is_string($line) && 1 === \preg_match('/^#\s*version\s+(\S+)/i', $line, $m)) {
                    IntlError::clear();

                    return $m[1];
                }
            }
        }
        $versionFile = self::ZONEINFO_ROOT.'/+VERSION';
        if (\is_file($versionFile)) {
            $raw = \trim((string) \file_get_contents($versionFile));
            if ('' !== $raw) {
                IntlError::clear();

                return $raw;
            }
        }
        IntlError::clear();

        return '2022e';
    }

    /**
     * Sorted equivalent IDs for $timezoneId, or [] if the zone is unknown.
     *
     * @return list<string>
     */
    private static function equivalentIds(string $timezoneId): array
    {
        $timezoneId = \trim($timezoneId);
        if ('' === $timezoneId || 'Etc/Unknown' === $timezoneId || 'unknown' === \strtolower($timezoneId)) {
            return [];
        }
        self::ensureEquivalentIndex();
        if (isset(self::$equivalentById[$timezoneId])) {
            return self::$equivalentById[$timezoneId];
        }
        // Accept IDs that validate but were not walked (rare); singleton clique.
        try {
            $id = VmDateTimeNative::validateTimezoneId($timezoneId);
        } catch (\Throwable) {
            return [];
        }
        if (isset(self::$equivalentById[$id])) {
            return self::$equivalentById[$id];
        }

        return [$id];
    }

    private static function ensureOlsonToWindows(): void
    {
        if (null !== self::$olsonToWindows) {
            return;
        }
        self::$olsonToWindows = [];
        foreach (self::WINDOWS_TO_OLSON as $win => $olson) {
            self::$olsonToWindows[$olson] = $win;
        }
    }

    private static function ensureEquivalentIndex(): void
    {
        if (null !== self::$equivalentById) {
            return;
        }
        self::$equivalentByReal = [];
        self::$equivalentById = [];
        $root = self::ZONEINFO_ROOT;
        if (!\is_dir($root)) {
            return;
        }
        // Walk full zoneinfo (including legacy US/*, GB, … aliases) so cliques match ICU.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        $rootLen = \strlen($root) + 1;
        foreach ($iterator as $file) {
            if (!$file->isFile() && !$file->isLink()) {
                continue;
            }
            $path = $file->getPathname();
            $base = \basename($path);
            if ('posixrules' === $base || \str_starts_with($base, '+') || \str_contains($base, '.')) {
                continue;
            }
            $id = \str_replace(\DIRECTORY_SEPARATOR, '/', \substr($path, $rootLen));
            if ('' === $id) {
                continue;
            }
            $real = \realpath($path);
            if (false === $real) {
                continue;
            }
            self::$equivalentByReal[$real][] = $id;
        }
        foreach (self::$equivalentByReal as $group) {
            $group = \array_values(\array_unique($group));
            \sort($group, \SORT_STRING);
            foreach ($group as $id) {
                self::$equivalentById[$id] = $group;
            }
        }
    }

    public static function getErrorCode(ObjectEntry $tz): int|false
    {
        if (!isset(self::$state[$tz->id])) {
            return false;
        }

        return IntlError::getCode();
    }

    public static function getErrorMessage(ObjectEntry $tz): string|false
    {
        if (!isset(self::$state[$tz->id])) {
            return false;
        }
        $msg = IntlError::getMessage();

        return '' === $msg ? 'U_ZERO_ERROR' : $msg;
    }

    public static function createFromId(Context $ctx, string $id): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlTimeZone" not found');
        }
        $canonical = self::resolveTimezoneId($id);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = ['id' => $canonical];
        IntlError::clear();

        return $object;
    }

    public static function createDefault(Context $ctx): ObjectEntry
    {
        return self::createFromId($ctx, VmDate::defaultTimezoneGet());
    }

    /**
     * Resolve createInstance / createTimeZone timezone operand (null|string|DateTimeZone|IntlTimeZone).
     */
    public static function resolveTimezoneOperand(Variable $var, Context $ctx, string $function, int $position): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return VmDate::defaultTimezoneGet();
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if (self::isTimeZoneObject($obj)) {
                return self::idOf($obj);
            }
            if ('datetimezone' === strtolower($obj->class->name)) {
                return DateTimeSupport::timezoneName($obj);
            }
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($timezone) must be of type IntlTimeZone|DateTimeZone|string|null, %s given',
                $function,
                $position + 1,
                $obj->class->name
            ));
        }

        return self::resolveTimezoneId(
            VmString::coerceStringBuiltinArg($var, $function, $position, 'timezone')
        );
    }

    public static function resolveTimezoneId(string $id): string
    {
        $id = trim($id);
        // ICU TimeZone::createTimeZone — empty / unknown → TimeZone::getUnknown() (#25356).
        // php-src timezone_methods.cpp intltz_create_time_zone; Zend leaves U_ZERO_ERROR.
        if ('' === $id || 'Etc/Unknown' === $id || 'unknown' === strtolower($id)) {
            return 'Etc/Unknown';
        }
        try {
            return VmDateTimeNative::validateTimezoneId($id);
        } catch (\Throwable) {
            return 'Etc/Unknown';
        }
    }

    /**
     * Zoneinfo offset meta — raw/DST in milliseconds (php-src TimeZone::getRawOffset / getDSTSavings).
     *
     * @return array{rawMs: int, dstMs: int, useDst: bool, abbrStd: string, abbrDst: string}
     */
    public static function offsetMeta(string $id): array
    {
        $id = self::resolveTimezoneId($id);
        // ICU TimeZone::getUnknown() — raw 0, DST savings 1h unused, GMT abbrs.
        // Must not call exportTimezoneTransitions / pushProcessTimezone: host
        // date_default_timezone_set('Etc/Unknown') emits E_NOTICE (#25789 / re-#25356).
        if ('Etc/Unknown' === $id) {
            return [
                'rawMs' => 0,
                'dstMs' => 3600000,
                'useDst' => false,
                'abbrStd' => 'GMT',
                'abbrDst' => 'GMT',
            ];
        }
        // 2000-01-01 .. 2030-01-01 covers modern DST rules for Olson zones.
        $transitions = VmDateTimeNative::exportTimezoneTransitions($id, 946684800, 1893456000);
        $rawSec = null;
        $dstSav = 0;
        $useDst = false;
        $abbrStd = $id;
        $abbrDst = $id;
        if (\is_array($transitions)) {
            foreach ($transitions as $t) {
                if (!$t['isdst']) {
                    $rawSec = $t['offset'];
                    if ('' !== $t['abbr']) {
                        $abbrStd = $t['abbr'];
                    }
                } else {
                    $useDst = true;
                    if ('' !== $t['abbr']) {
                        $abbrDst = $t['abbr'];
                    }
                    if (null !== $rawSec) {
                        $dstSav = max($dstSav, $t['offset'] - $rawSec);
                    }
                }
            }
            if (null === $rawSec && isset($transitions[0])) {
                $rawSec = $transitions[0]['offset'];
                if ('' !== $transitions[0]['abbr']) {
                    $abbrStd = $transitions[0]['abbr'];
                }
            }
        }
        if (null === $rawSec) {
            $rawSec = VmDateTimeNative::timezoneOffsetSeconds($id, 1705312800); // 2024-01-15 12:00 UTC
        }
        if ($useDst && $dstSav <= 0) {
            $dstSav = 3600;
        }
        if (!$useDst) {
            $dstSav = 0;
            $abbrDst = $abbrStd;
        }

        return [
            'rawMs' => $rawSec * 1000,
            'dstMs' => $dstSav * 1000,
            'useDst' => $useDst,
            'abbrStd' => $abbrStd,
            'abbrDst' => $abbrDst,
        ];
    }

    public static function getRawOffset(ObjectEntry $object): int
    {
        return self::offsetMeta(self::idOf($object))['rawMs'];
    }

    public static function getDSTSavings(ObjectEntry $object): int
    {
        return self::offsetMeta(self::idOf($object))['dstMs'];
    }

    public static function useDaylightTime(ObjectEntry $object): bool
    {
        return self::offsetMeta(self::idOf($object))['useDst'];
    }

    /**
     * ICU English long / generic display names for Olson IDs (en_US; #22004).
     *
     * Locale-sensitive ICU resource bundles are deferred — this curated table matches
     * TimeZone::getDisplayName(DISPLAY_LONG|LONG_GENERIC) under en_US for the Windows↔Olson
     * subset already shipped for #20852, plus UTC/GMT aliases.
     *
     * @var array<string, array{std: string, dst: string, generic: string}>
     */
    private const EN_LONG_DISPLAY = [
        'UTC' => ['std' => 'Coordinated Universal Time', 'dst' => 'GMT', 'generic' => 'GMT'],
        'Etc/UTC' => ['std' => 'Coordinated Universal Time', 'dst' => 'GMT', 'generic' => 'GMT'],
        'GMT' => ['std' => 'Greenwich Mean Time', 'dst' => 'GMT', 'generic' => 'Greenwich Mean Time'],
        'Etc/GMT' => ['std' => 'Greenwich Mean Time', 'dst' => 'GMT', 'generic' => 'Greenwich Mean Time'],
        'America/New_York' => ['std' => 'Eastern Standard Time', 'dst' => 'Eastern Daylight Time', 'generic' => 'Eastern Time'],
        'America/Chicago' => ['std' => 'Central Standard Time', 'dst' => 'Central Daylight Time', 'generic' => 'Central Time'],
        'America/Denver' => ['std' => 'Mountain Standard Time', 'dst' => 'Mountain Daylight Time', 'generic' => 'Mountain Time'],
        'America/Los_Angeles' => ['std' => 'Pacific Standard Time', 'dst' => 'Pacific Daylight Time', 'generic' => 'Pacific Time'],
        'America/Anchorage' => ['std' => 'Alaska Standard Time', 'dst' => 'Alaska Daylight Time', 'generic' => 'Alaska Time'],
        'Pacific/Honolulu' => ['std' => 'Hawaii-Aleutian Standard Time', 'dst' => 'Hawaii-Aleutian Daylight Time', 'generic' => 'Hawaii-Aleutian Standard Time'],
        'Europe/London' => ['std' => 'Greenwich Mean Time', 'dst' => 'British Summer Time', 'generic' => 'United Kingdom Time'],
        'Atlantic/Reykjavik' => ['std' => 'Greenwich Mean Time', 'dst' => 'GMT', 'generic' => 'Greenwich Mean Time'],
        'Europe/Berlin' => ['std' => 'Central European Standard Time', 'dst' => 'Central European Summer Time', 'generic' => 'Central European Time'],
        'Europe/Budapest' => ['std' => 'Central European Standard Time', 'dst' => 'Central European Summer Time', 'generic' => 'Central European Time'],
        'Europe/Paris' => ['std' => 'Central European Standard Time', 'dst' => 'Central European Summer Time', 'generic' => 'Central European Time'],
        'Europe/Bucharest' => ['std' => 'Eastern European Standard Time', 'dst' => 'Eastern European Summer Time', 'generic' => 'Eastern European Time'],
        'Europe/Helsinki' => ['std' => 'Eastern European Standard Time', 'dst' => 'Eastern European Summer Time', 'generic' => 'Eastern European Time'],
        'Europe/Moscow' => ['std' => 'Moscow Standard Time', 'dst' => 'Moscow Summer Time', 'generic' => 'Moscow Standard Time'],
        'Asia/Tokyo' => ['std' => 'Japan Standard Time', 'dst' => 'Japan Daylight Time', 'generic' => 'Japan Standard Time'],
        'Asia/Shanghai' => ['std' => 'China Standard Time', 'dst' => 'China Daylight Time', 'generic' => 'China Standard Time'],
        'Asia/Singapore' => ['std' => 'Singapore Standard Time', 'dst' => 'GMT+08:00', 'generic' => 'Singapore Standard Time'],
        'Asia/Seoul' => ['std' => 'Korean Standard Time', 'dst' => 'Korean Daylight Time', 'generic' => 'Korean Standard Time'],
        'Asia/Kolkata' => ['std' => 'India Standard Time', 'dst' => 'GMT+05:30', 'generic' => 'India Standard Time'],
        'Australia/Sydney' => ['std' => 'Australian Eastern Standard Time', 'dst' => 'Australian Eastern Daylight Time', 'generic' => 'Eastern Australia Time'],
        'Pacific/Auckland' => ['std' => 'New Zealand Standard Time', 'dst' => 'New Zealand Daylight Time', 'generic' => 'New Zealand Time'],
    ];

    /**
     * php-src intltz_get_display_name — zoneinfo abbr / GMT / ICU English long forms (#20769, #22004).
     */
    public static function getDisplayName(
        ObjectEntry $object,
        bool $dst = false,
        int $style = self::DISPLAY_LONG,
        ?string $locale = null
    ): string|false {
        return self::displayNameForId(self::idOf($object), $dst, $style, $locale);
    }

    /**
     * Display name for an Olson ID (no ObjectEntry) — shared by IntlTimeZone + IntlDateFormatter (#22004).
     */
    public static function displayNameForId(
        string $id,
        bool $dst = false,
        int $style = self::DISPLAY_LONG,
        ?string $locale = null
    ): string|false {
        unset($locale); // non-en_US ICU resource bundles deferred; English table below.
        $id = self::resolveTimezoneId($id);
        $meta = self::offsetMeta($id);
        $offsetMs = $meta['rawMs'] + ($dst ? $meta['dstMs'] : 0);
        $abbr = $dst ? $meta['abbrDst'] : $meta['abbrStd'];

        return match ($style) {
            self::DISPLAY_SHORT,
            self::DISPLAY_SHORT_GENERIC,
            self::DISPLAY_SHORT_COMMONLY_USED => '' !== $abbr ? $abbr : $id,
            self::DISPLAY_SHORT_GMT => self::formatGmtOffset($offsetMs, false),
            self::DISPLAY_LONG_GMT => self::formatGmtOffset($offsetMs, true),
            self::DISPLAY_LONG => self::englishLongName($id, $dst, $abbr),
            self::DISPLAY_LONG_GENERIC,
            self::DISPLAY_GENERIC_LOCATION => self::englishGenericName($id, $abbr),
            default => false,
        };
    }

    private static function englishLongName(string $id, bool $dst, string $abbr): string
    {
        $row = self::EN_LONG_DISPLAY[$id] ?? null;
        if (null !== $row) {
            return $dst ? $row['dst'] : $row['std'];
        }

        return '' !== $abbr ? $abbr : $id;
    }

    private static function englishGenericName(string $id, string $abbr): string
    {
        $row = self::EN_LONG_DISPLAY[$id] ?? null;
        if (null !== $row) {
            return $row['generic'];
        }

        return '' !== $abbr ? $abbr : $id;
    }

    public static function toDateTimeZone(Context $ctx, ObjectEntry $object): ObjectEntry|false
    {
        try {
            $var = DateTimeSupport::newDateTimeZoneVariable($ctx, self::idOf($object));

            return $var->toObject();
        } catch (\Throwable) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intltz_to_date_time_zone: DateTimeZone create failed: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
    }

    public static function fromDateTimeZone(Context $ctx, ObjectEntry $zone): ObjectEntry
    {
        return self::createFromId($ctx, DateTimeSupport::timezoneName($zone));
    }

    public static function getRegion(string $timezoneId): string|false
    {
        try {
            $id = VmDateTimeNative::validateTimezoneId($timezoneId);
        } catch (\Throwable) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intltz_get_region: No such time zone: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $loc = VmDateTimeNative::timezoneLocation($id);
        if (false === $loc) {
            // Etc/UTC and fixed offsets — ICU uses "001" (world).
            return '001';
        }
        $cc = $loc['country_code'];
        if ('??' === $cc || '' === $cc) {
            return '001';
        }

        return $cc;
    }

    /**
     * @param-out bool|null $isSystemId
     */
    public static function getCanonicalID(string $timezoneId, ?bool &$isSystemId = null): string|false
    {
        try {
            $id = VmDateTimeNative::validateTimezoneId($timezoneId);
            $isSystemId = true;
            IntlError::clear();

            return $id;
        } catch (\Throwable) {
            $isSystemId = null;
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intltz_get_canonical_id: No such time zone: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
    }

    /**
     * php-src intltz_get_iana_id / TimeZone::getIanaID (ICU≥74) — resolve zoneinfo
     * symlinks to the IANA id (e.g. US/Pacific → America/Los_Angeles) (#20926).
     */
    public static function getIanaID(string $timezoneId): string|false
    {
        try {
            $id = VmDateTimeNative::validateTimezoneId($timezoneId);
        } catch (\Throwable) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'intltz_get_iana_id: No such time zone: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }

        $root = self::ZONEINFO_ROOT;
        $path = $root.'/'.$id;
        if (is_link($path) || is_file($path)) {
            $real = realpath($path);
            if (false !== $real) {
                $prefix = $root.'/';
                if (str_starts_with($real, $prefix)) {
                    $iana = substr($real, strlen($prefix));
                    if ('' !== $iana && !str_contains($iana, "\0")) {
                        IntlError::clear();

                        return $iana;
                    }
                }
            }
        }

        IntlError::clear();

        return $id;
    }

    /**
     * php-src intltz_get_offset — fills raw/dst offset by-ref in milliseconds.
     *
     * @param-out int $rawOffset
     * @param-out int $dstOffset
     */
    public static function getOffset(
        ObjectEntry $object,
        float $timestamp,
        bool $local,
        int &$rawOffset,
        int &$dstOffset
    ): bool {
        unset($local); // local wall-time form deferred; dateMs treated as UTC epoch ms.
        $id = self::idOf($object);
        $meta = self::offsetMeta($id);
        $rawOffset = $meta['rawMs'];
        // ICU unknown zone: total offset always 0 — skip process-TZ push (#25789).
        if ('Etc/Unknown' === self::resolveTimezoneId($id)) {
            $dstOffset = 0;
            IntlError::clear();

            return true;
        }
        $ts = (int) floor($timestamp / 1000.0);
        $totalSec = VmDateTimeNative::timezoneOffsetSeconds($id, $ts);
        $dstOffset = ($totalSec * 1000) - $rawOffset;
        if ($dstOffset < 0) {
            $dstOffset = 0;
        }
        IntlError::clear();

        return true;
    }

    public static function hasSameRules(ObjectEntry $a, ObjectEntry $b): bool
    {
        $ma = self::offsetMeta(self::idOf($a));
        $mb = self::offsetMeta(self::idOf($b));

        return $ma['rawMs'] === $mb['rawMs']
            && $ma['dstMs'] === $mb['dstMs']
            && $ma['useDst'] === $mb['useDst'];
    }

    public static function requireTimeZoneReceiver(Variable $receiver, string $method): ObjectEntry
    {
        $receiver = $receiver->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !self::isTimeZoneObject($receiver->toObject())) {
            throw new \Error($method.'() called on incompatible object');
        }

        return $receiver->toObject();
    }

    private static function formatGmtOffset(int $offsetMs, bool $long): string
    {
        $sign = $offsetMs >= 0 ? '+' : '-';
        $abs = abs($offsetMs);
        $hours = intdiv($abs, 3600000);
        $mins = intdiv($abs % 3600000, 60000);
        if ($long) {
            return \sprintf('GMT%s%02d:%02d', $sign, $hours, $mins);
        }
        if (0 === $mins) {
            return \sprintf('GMT%s%d', $sign, $hours);
        }

        return \sprintf('GMT%s%d:%02d', $sign, $hours, $mins);
    }
}

/** IntlTimeZone::createTimeZone() — php-src intltz_create_time_zone (#6151). */
final class IntlTimeZoneCreateTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::createTimeZone() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'IntlTimeZone::createTimeZone',
            0,
            'zoneId'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createFromId($frame->vmContext, $id));
    }
}

/** IntlTimeZone::createDefault() — php-src intltz_create_default (#6151). */
final class IntlTimeZoneCreateDefault extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createDefault');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::createDefault() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createDefault($frame->vmContext));
    }
}

/** IntlTimeZone::getID() — php-src intltz_get_id (#6151). */
final class IntlTimeZoneGetID extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getID');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getID() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::getID');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmIntlTimeZone::idOf($object));
    }
}

/** IntlTimeZone::getRawOffset() — php-src intltz_get_raw_offset (#20769). */
final class IntlTimeZoneGetRawOffset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRawOffset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getRawOffset() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::getRawOffset');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlTimeZone::getRawOffset($object));
    }
}

/** IntlTimeZone::getDSTSavings() — php-src intltz_get_dst_savings (#20769). */
final class IntlTimeZoneGetDSTSavings extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDSTSavings');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getDSTSavings() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::getDSTSavings');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlTimeZone::getDSTSavings($object));
    }
}

/** IntlTimeZone::useDaylightTime() — php-src intltz_use_daylight_time (#20769). */
final class IntlTimeZoneUseDaylightTime extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('useDaylightTime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::useDaylightTime() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::useDaylightTime');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlTimeZone::useDaylightTime($object));
    }
}

/** IntlTimeZone::getDisplayName() — php-src intltz_get_display_name (#20769). */
final class IntlTimeZoneGetDisplayName extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDisplayName');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getDisplayName() expects between 0 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::getDisplayName');
        $dst = false;
        if ($argc >= 2) {
            $dst = LocaleLookup::coerceBool(
                $frame->calledArgs[1],
                'IntlTimeZone::getDisplayName',
                1,
                'dst'
            );
        }
        $style = VmIntlTimeZone::DISPLAY_LONG;
        if ($argc >= 3) {
            $style = VmIntlDateFormatter::coerceIntArg(
                $frame->calledArgs[2],
                'IntlTimeZone::getDisplayName',
                2,
                'style'
            );
        }
        $locale = null;
        if ($argc >= 4) {
            $localeVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[3],
                    'IntlTimeZone::getDisplayName',
                    3,
                    'locale'
                );
            }
        }
        $name = VmIntlTimeZone::getDisplayName($object, $dst, $style, $locale);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $name) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($name);
    }
}

/** IntlTimeZone::toDateTimeZone() — php-src intltz_to_date_time_zone (#20769). */
final class IntlTimeZoneToDateTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('toDateTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::toDateTimeZone() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::toDateTimeZone');
        $zone = VmIntlTimeZone::toDateTimeZone($frame->vmContext, $object);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $zone) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($zone);
    }
}

/** IntlTimeZone::getOffset() — php-src intltz_get_offset (#20769). */
final class IntlTimeZoneGetOffset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getOffset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (5 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getOffset() expects exactly 4 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::getOffset');
        $tsVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_FLOAT === $tsVar->type) {
            $timestamp = $tsVar->toFloat();
        } elseif (Variable::TYPE_INTEGER === $tsVar->type) {
            $timestamp = (float) $tsVar->toInt();
        } else {
            throw new \TypeError(\sprintf(
                'IntlTimeZone::getOffset(): Argument #1 ($date) must be of type float, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($tsVar)
            ));
        }
        $local = LocaleLookup::coerceBool(
            $frame->calledArgs[2],
            'IntlTimeZone::getOffset',
            2,
            'local'
        );
        $raw = 0;
        $dst = 0;
        $ok = VmIntlTimeZone::getOffset($object, $timestamp, $local, $raw, $dst);
        $frame->calledArgs[3]->resolveIndirect()->int($raw);
        $frame->calledArgs[4]->resolveIndirect()->int($dst);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** IntlTimeZone::hasSameRules() — php-src intltz_has_same_rules (#20769). */
final class IntlTimeZoneHasSameRules extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasSameRules');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::hasSameRules() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmIntlTimeZone::requireTimeZoneReceiver($frame->calledArgs[0], 'IntlTimeZone::hasSameRules');
        $otherVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $otherVar->type
            || !VmIntlTimeZone::isTimeZoneObject($otherVar->toObject())) {
            throw new \TypeError(\sprintf(
                'IntlTimeZone::hasSameRules(): Argument #1 ($otherTimeZone) must be of type IntlTimeZone, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($otherVar)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlTimeZone::hasSameRules($object, $otherVar->toObject()));
    }
}

/** IntlTimeZone::getRegion() — php-src intltz_get_region (#20769). */
final class IntlTimeZoneGetRegion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getRegion');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getRegion() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'IntlTimeZone::getRegion',
            0,
            'zoneId'
        );
        $region = VmIntlTimeZone::getRegion($id);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $region) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($region);
    }
}

/** IntlTimeZone::getIanaID() — php-src intltz_get_iana_id (#20926). */
final class IntlTimeZoneGetIanaID extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIanaID');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getIanaID() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'IntlTimeZone::getIanaID',
            0,
            'zoneId'
        );
        $iana = VmIntlTimeZone::getIanaID($id);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $iana) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($iana);
    }
}

/** IntlTimeZone::getCanonicalID() — php-src intltz_get_canonical_id (#20769). */
final class IntlTimeZoneGetCanonicalID extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getCanonicalID');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getCanonicalID() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'IntlTimeZone::getCanonicalID',
            0,
            'zoneId'
        );
        $isSystemId = null;
        $canonical = VmIntlTimeZone::getCanonicalID($id, $isSystemId);
        if ($argc >= 2) {
            $out = $frame->calledArgs[1]->resolveIndirect();
            if (null === $isSystemId) {
                $out->null();
            } else {
                $out->bool($isSystemId);
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $canonical) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($canonical);
    }
}

/** IntlTimeZone::fromDateTimeZone() — php-src intltz_from_date_time_zone (#20769). */
final class IntlTimeZoneFromDateTimeZone extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromDateTimeZone');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::fromDateTimeZone() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $zoneVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $zoneVar->type
            || 'datetimezone' !== strtolower($zoneVar->toObject()->class->name)) {
            throw new \TypeError(\sprintf(
                'IntlTimeZone::fromDateTimeZone(): Argument #1 ($timezone) must be of type DateTimeZone, %s given',
                \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($zoneVar)
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(
            VmIntlTimeZone::fromDateTimeZone($frame->vmContext, $zoneVar->toObject())
        );
    }
}

/** IntlTimeZone::getGMT() — php-src intltz_get_gmt (#20769). */
final class IntlTimeZoneGetGMT extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getGMT');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getGMT() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlTimeZone::createFromId($frame->vmContext, 'GMT'));
    }
}

/** IntlTimeZone::getUnknown() — php-src intltz_get_unknown (#20852). */
final class IntlTimeZoneGetUnknown extends VmClassMethod
{
    public function __construct() { parent::__construct('getUnknown'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::getUnknown() expects exactly 0 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->object(VmIntlTimeZone::createFromId($frame->vmContext, 'Etc/Unknown'));
    }
}

/** IntlTimeZone::getUTC() — php-src intltz_get_utc (#20852). */
final class IntlTimeZoneGetUTC extends VmClassMethod
{
    public function __construct() { parent::__construct('getUTC'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::getUTC() expects exactly 0 arguments, %d given', $argc));
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->object(VmIntlTimeZone::createFromId($frame->vmContext, 'UTC'));
    }
}

/** IntlTimeZone::createEnumeration() — php-src intltz_create_enumeration (#20852). */
final class IntlTimeZoneCreateEnumeration extends VmClassMethod
{
    public function __construct() { parent::__construct('createEnumeration'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::createEnumeration() expects at most 1 argument, %d given', $argc));
        }
        $countryOrZone = null;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_INTEGER === $arg->type) {
                    // php-src also accepts int country? treat as string cast of number — rare; ignore filter
                    $countryOrZone = null;
                } else {
                    $countryOrZone = VmString::coerceStringBuiltinArg($arg, 'IntlTimeZone::createEnumeration', 1, 'countryOrZoneId');
                }
            }
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->object(VmIntlTimeZone::createEnumeration($frame->vmContext, $countryOrZone));
    }
}

/** IntlTimeZone::createTimeZoneIDEnumeration() — php-src intltz_create_timezone_id_enumeration (#20852). */
final class IntlTimeZoneCreateTimeZoneIDEnumeration extends VmClassMethod
{
    public function __construct() { parent::__construct('createTimeZoneIDEnumeration'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::createTimeZoneIDEnumeration() expects between 1 and 3 arguments, %d given', $argc));
        }
        $zoneType = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[0], 'IntlTimeZone::createTimeZoneIDEnumeration', 1, 'type');
        $region = null;
        if ($argc >= 2) {
            $r = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $r->type) {
                $region = VmString::coerceStringBuiltinArg($r, 'IntlTimeZone::createTimeZoneIDEnumeration', 2, 'region');
            }
        }
        $rawOffset = null;
        if ($argc >= 3) {
            $o = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $o->type) {
                $rawOffset = VmIntlDateFormatter::coerceIntArg($o, 'IntlTimeZone::createTimeZoneIDEnumeration', 3, 'rawOffset');
            }
        }
        if (null === $frame->returnVar) { return; }
        $frame->returnVar->object(VmIntlTimeZone::createTimeZoneIDEnumeration(
            $frame->vmContext,
            $zoneType,
            $region,
            $rawOffset
        ));
    }
}

/** IntlTimeZone::getIDForWindowsID() — php-src intltz_get_id_for_windows_id (#20852). */
final class IntlTimeZoneGetIDForWindowsID extends VmClassMethod
{
    public function __construct() { parent::__construct('getIDForWindowsID'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::getIDForWindowsID() expects between 1 and 2 arguments, %d given', $argc));
        }
        $windowsId = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'IntlTimeZone::getIDForWindowsID', 1, 'windowsID');
        $region = null;
        if (2 === $argc) {
            $r = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $r->type) {
                $region = VmString::coerceStringBuiltinArg($r, 'IntlTimeZone::getIDForWindowsID', 2, 'region');
            }
        }
        $olson = VmIntlTimeZone::getIDForWindowsID($windowsId, $region);
        if (null === $frame->returnVar) { return; }
        if (false === $olson) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->string($olson);
    }
}

/** IntlTimeZone::getWindowsID() — php-src intltz_get_windows_id (#20824). */
final class IntlTimeZoneGetWindowsID extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getWindowsID');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getWindowsID() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'IntlTimeZone::getWindowsID', 1, 'timezoneId');
        $win = VmIntlTimeZone::getWindowsID($id);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $win) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($win);
    }
}

/** IntlTimeZone::countEquivalentIDs() — php-src intltz_count_equivalent_ids (#20824). */
final class IntlTimeZoneCountEquivalentIDs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('countEquivalentIDs');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::countEquivalentIDs() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'IntlTimeZone::countEquivalentIDs', 1, 'timezoneId');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlTimeZone::countEquivalentIDs($id));
    }
}

/** IntlTimeZone::getEquivalentID() — php-src intltz_get_equivalent_id (#20824). */
final class IntlTimeZoneGetEquivalentID extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getEquivalentID');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getEquivalentID() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $id = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'IntlTimeZone::getEquivalentID', 1, 'timezoneId');
        $index = VmIntlDateFormatter::coerceIntArg($frame->calledArgs[1], 'IntlTimeZone::getEquivalentID', 2, 'index');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmIntlTimeZone::getEquivalentID($id, $index));
    }
}

/** IntlTimeZone::getTZDataVersion() — php-src intltz_get_tz_data_version (#20824). */
final class IntlTimeZoneGetTZDataVersion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getTZDataVersion');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlTimeZone::getTZDataVersion() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmIntlTimeZone::getTZDataVersion());
    }
}

/** IntlTimeZone::getErrorCode() — php-src intltz_get_error_code (#20852). */
final class IntlTimeZoneGetErrorCode extends VmClassMethod
{
    public function __construct() { parent::__construct('getErrorCode'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::getErrorCode() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \Error('IntlTimeZone::getErrorCode() called on incompatible object');
        }
        $code = VmIntlTimeZone::getErrorCode($receiver->toObject());
        if (null === $frame->returnVar) { return; }
        if (false === $code) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->int($code);
    }
}

/** IntlTimeZone::getErrorMessage() — php-src intltz_get_error_message (#20852). */
final class IntlTimeZoneGetErrorMessage extends VmClassMethod
{
    public function __construct() { parent::__construct('getErrorMessage'); }
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf('IntlTimeZone::getErrorMessage() expects exactly 0 arguments, %d given', max(0, $argc - 1)));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type || !VmIntlTimeZone::isTimeZoneObject($receiver->toObject())) {
            throw new \Error('IntlTimeZone::getErrorMessage() called on incompatible object');
        }
        $msg = VmIntlTimeZone::getErrorMessage($receiver->toObject());
        if (null === $frame->returnVar) { return; }
        if (false === $msg) { $frame->returnVar->bool(false); return; }
        $frame->returnVar->string($msg);
    }
}
