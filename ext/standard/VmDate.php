<?php

declare(strict_types=1);

/**
 * VM date/time helpers via VmDatePure host builtins (#13765, #5045).
 *
 * php-src: ext/date/php_date.c — time, date, gmdate, microtime, getdate.
 * JIT/AOT: JitDate.php, FormatDatetimeJitHelper/MicrotimeJitHelper/GettimeofdayJitHelper PHP.
 */
namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;

final class VmDate
{
    /** php-src PG(date.timezone) default when unset (#3292). */
    private static string $defaultTimezone = 'UTC';

    /** date_sunrise()/date_sunset() return format (ext/date/php_date.c, PHP 8.4 values, #6137). */
    public const SUNFUNCS_RET_TIMESTAMP = 0;
    public const SUNFUNCS_RET_STRING = 1;
    public const SUNFUNCS_RET_DOUBLE = 2;

    private const FORMAT_OUT_BYTES = 256;

    public static function time(): int
    {
        return VmDatePure::time();
    }

    public static function getmypid(): int
    {
        $pid = VmProcessIdentityNative::getpid();
        if (null !== $pid) {
            return $pid;
        }

        return 0;
    }

    /** getmygrgid() — real group id (ext/standard/basic_functions.c, #3611, native #7891). */
    public static function getmygrgid(): int
    {
        return VmProcessIdentity::getmygid();
    }

    /**
     * timezone_version_get() — Olson/tzdata version (ext/date/php_date.c, #6832, #8032, #29386).
     *
     * PHP-owned: read the IANA version from system zoneinfo (`tzdata.zi` / `+VERSION`).
     * Falls back to php-src `0.system` only when no zoneinfo database is present — never
     * delegates to host Zend ext/date for the version string (self-host/AOT shrink, #8032).
     */
    public static function timezone_version_get(): string
    {
        return VmDateTimeNative::timezoneDbVersion();
    }

    /**
     * date_sun_info() — sunrise/sunset and twilight timestamps (ext/date/php_date.c, #6831).
     */
    public static function dateSunInfo(int $time, float $latitude, float $longitude): HashTable
    {
        return self::arrayToHashTable(self::dateSunInfoNative($time, $latitude, $longitude));
    }

    /**
     * @return array<string, int|bool>
     */
    public static function dateSunInfoNative(int $time, float $latitude, float $longitude): array
    {
        if (!\is_finite($latitude)) {
            throw new \ValueError('date_sun_info(): Argument #2 ($latitude) must be finite');
        }
        if (!\is_finite($longitude)) {
            throw new \ValueError('date_sun_info(): Argument #3 ($longitude) must be finite');
        }

        return VmDateSunNative::sunInfo($time, $latitude, $longitude);
    }

    /**
     * date_sunrise() — procedural sunrise helper (ext/date/php_date.c, #6137).
     *
     * @return string|int|float|false
     */
    public static function dateSunrise(
        int $timestamp,
        int $returnFormat = self::SUNFUNCS_RET_STRING,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $zenith = null,
        ?float $gmtOffset = null,
        int $argc = 1
    ): mixed {
        return self::dateSunFuncNative(
            'date_sunrise',
            $timestamp,
            $returnFormat,
            $latitude,
            $longitude,
            $zenith,
            $gmtOffset,
            $argc
        );
    }

    /**
     * date_sunset() — procedural sunset helper (ext/date/php_date.c, #6137).
     *
     * @return string|int|float|false
     */
    public static function dateSunset(
        int $timestamp,
        int $returnFormat = self::SUNFUNCS_RET_STRING,
        ?float $latitude = null,
        ?float $longitude = null,
        ?float $zenith = null,
        ?float $gmtOffset = null,
        int $argc = 1
    ): mixed {
        return self::dateSunFuncNative(
            'date_sunset',
            $timestamp,
            $returnFormat,
            $latitude,
            $longitude,
            $zenith,
            $gmtOffset,
            $argc
        );
    }

    /**
     * @return string|int|float|false
     */
    private static function dateSunFuncNative(
        string $function,
        int $timestamp,
        int $returnFormat,
        ?float $latitude,
        ?float $longitude,
        ?float $zenith,
        ?float $gmtOffset,
        int $argc
    ): mixed {
        return VmDateSunNative::sunriseSunset(
            'date_sunset' === $function,
            $timestamp,
            $returnFormat,
            $latitude,
            $longitude,
            $zenith,
            $gmtOffset,
            $argc
        );
    }

    /**
     * @param string|int|float|false $result
     */
    public static function writeSunFuncReturn(Frame $frame, mixed $result): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        if (\is_float($result)) {
            $frame->returnVar->float($result);

            return;
        }
        $frame->returnVar->string((string) $result);
    }

    /**
     * @param array<string, int|bool> $data
     */
    public static function arrayToHashTable(array $data): HashTable
    {
        $ht = new HashTable();
        foreach ($data as $key => $value) {
            if (\is_int($value)) {
                self::hashSetLong($ht, (string) $key, $value);
            } elseif (\is_bool($value)) {
                self::hashSetBool($ht, (string) $key, $value);
            }
        }

        return $ht;
    }

    /**
     * getmyinode() — inode of the executed script (ext/standard/basic_functions.c, #3611).
     *
     * @return int|false
     */
    public static function getmyinode(Frame $frame)
    {
        $path = self::executedFilename($frame);
        if ('' === $path || '-' === $path) {
            return false;
        }
        $stat = VmStatCache::stat($path);
        if (false === $stat) {
            return false;
        }

        return (int) ($stat['ino'] ?? 0);
    }

    /**
     * getlastmod() — mtime of the executed script (ext/standard/basic_functions.c, #5068).
     *
     * @return int|false
     */
    public static function getlastmod(Frame $frame)
    {
        $path = self::executedFilename($frame);
        if ('' === $path || '-' === $path) {
            return false;
        }

        return VmFs::fileMtime($path);
    }

    private static function executedFilename(Frame $frame): string
    {
        if (null !== $frame->vmContext) {
            $root = $frame->vmContext->scriptStack->root();
            if ('' !== $root) {
                return $root;
            }
        }
        $f = $frame;
        while (null !== $f->parent) {
            $f = $f->parent;
        }
        if ('' !== $f->scriptPath) {
            return $f->scriptPath;
        }
        if (null !== $f->block) {
            return $f->block->scriptPath();
        }

        return '';
    }

    public static function date(string $format, ?int $timestamp = null): string
    {
        return self::formatDateTime($format, $timestamp ?? self::time(), false);
    }

    public static function gmdate(string $format, ?int $timestamp = null): string
    {
        return self::formatDateTime($format, $timestamp ?? self::time(), true);
    }

    /**
     * strftime() / gmstrftime() — locale time via libc strftime (ext/standard/datetime.c, #3692).
     */
    public static function strftime(string $format, ?int $timestamp = null): string|false
    {
        return self::libcStrftime($format, $timestamp ?? self::time(), false);
    }

    public static function gmstrftime(string $format, ?int $timestamp = null): string|false
    {
        return self::libcStrftime($format, $timestamp ?? self::time(), true);
    }

    /**
     * strptime() — parse date/time string to tm array (ext/standard/datetime.c, #3694).
     *
     * php-src: ext/standard/datetime.c — PHP_FUNCTION(strptime)
     */
    public static function strptime(string $date, string $format): HashTable|false
    {
        // SSOT: StrptimeJitHelper pure PHP (no host \strptime) (#22771).
        return StrptimeJitHelper::strptimeArgv($date, $format);
    }

    /**
     * Coerce optional ?int timestamp with caller strict_types (ext/date/php_date.c, #14892).
     *
     * @throws \TypeError when strict_types rejects float or operand is not int|null
     */
    public static function coerceNullableTimestampArgForFrame(
        Frame $frame,
        int $argIndex,
        string $function,
        int $userArgIndex,
        string $paramName = 'timestamp'
    ): ?int {
        $var = $frame->calledArgs[$argIndex];
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireNullableInt($frame, $argIndex, $function, $paramName);

            return $resolved->toInt();
        }
        if (Variable::TYPE_FLOAT === $resolved->type && null !== $frame->vmContext) {
            VmMath::warnFloatToIntPrecisionLoss($resolved->toFloat(), $frame->vmContext, $frame);
        }

        return self::coerceNullableTimestampArg($var, $function, $userArgIndex, $paramName);
    }

    /**
     * Coerce optional ?int timestamp for date()/gmdate()/getdate() family (php-src Z_PARAM_LONG_OR_NULL, #5842).
     *
     * @throws \TypeError when operand is not int|null (enum cases name the enum class, not backing int)
     */
    public static function coerceNullableTimestampArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName = 'timestamp'
    ): ?int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return $var->toInt();
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(self::nullableTimestampTypeError(
                $function,
                $argIndex,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        throw new \TypeError(self::nullableTimestampTypeError(
            $function,
            $argIndex,
            $paramName,
            self::timestampVmTypeName($var->type)
        ));
    }

    public static function nullableTimestampTypeError(
        string $function,
        int $argIndex,
        string $paramName,
        string $given
    ): string {
        return \sprintf(
            '%s(): Argument #%d ($%s) must be of type ?int, %s given',
            $function,
            $argIndex,
            $paramName,
            $given
        );
    }

    private static function timestampVmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };
    }

    /** @return string|float */
    public static function microtime(bool $asFloat = false)
    {
        $tv = self::readTimeval();
        if ($asFloat) {
            return (float) $tv['sec'] + (float) $tv['usec'] / 1_000_000.0;
        }

        return \sprintf('%.8f %d', (float) $tv['usec'] / 1_000_000.0, $tv['sec']);
    }

    /**
     * @return float|array{0: int, 1: int}
     */
    public static function hrtime(bool $asNumber = false)
    {
        return VmHrtime::hrtime($asNumber);
    }

    /**
     * idate() part value — php-src ext/date/php_date.c php_idate() (issue #6830).
     *
     * @return int|false false when format char is unrecognized
     */
    public static function idateValue(string $formatChar, int $timestamp): int|false
    {
        if (1 !== \strlen($formatChar)) {
            return false;
        }
        $tm = self::localtime($timestamp);
        if (null === $tm) {
            return false;
        }

        $sec = self::tmInt($tm, 'tm_sec');
        $min = self::tmInt($tm, 'tm_min');
        $hour = self::tmInt($tm, 'tm_hour');
        $mday = self::tmInt($tm, 'tm_mday');
        $mon = self::tmInt($tm, 'tm_mon') + 1;
        $year = self::tmInt($tm, 'tm_year') + 1900;
        $wday = self::tmInt($tm, 'tm_wday');
        $yday = self::tmInt($tm, 'tm_yday');
        $isdst = self::tmInt($tm, 'tm_isdst');

        return match ($formatChar) {
            'B' => self::swatchBeat($timestamp),
            'd', 'j' => $mday,
            'h', 'g' => self::hour12($hour),
            'H' => $hour,
            'i' => $min,
            'I' => $isdst > 0 ? 1 : 0,
            'L' => self::isLeapYear($year) ? 1 : 0,
            'm', 'n' => $mon,
            'N' => 0 === $wday ? 7 : $wday,
            's' => $sec,
            't' => self::daysInMonth($year, $mon),
            'U' => $timestamp,
            'w' => $wday,
            'W' => self::isoWeek($year, $mon, $mday),
            'y' => $year % 100,
            'Y' => $year,
            'z' => $yday,
            'o' => self::isoYear($year, $mon, $mday),
            default => false,
        };
    }

    /**
     * localtime() breakdown — php-src ext/standard/datetime.c PHP_FUNCTION(localtime) (#6812).
     */
    public static function localtimeBreakdown(?int $timestamp = null, bool $associative = false): HashTable
    {
        $ts = $timestamp ?? self::time();
        $tm = self::localtime($ts);
        $ht = new HashTable();
        if (null === $tm) {
            return $ht;
        }

        $values = [
            self::tmInt($tm, 'tm_sec'),
            self::tmInt($tm, 'tm_min'),
            self::tmInt($tm, 'tm_hour'),
            self::tmInt($tm, 'tm_mday'),
            self::tmInt($tm, 'tm_mon'),
            self::tmInt($tm, 'tm_year'),
            self::tmInt($tm, 'tm_wday'),
            self::tmInt($tm, 'tm_yday'),
            self::tmInt($tm, 'tm_isdst'),
        ];
        $keys = [
            'tm_sec',
            'tm_min',
            'tm_hour',
            'tm_mday',
            'tm_mon',
            'tm_year',
            'tm_wday',
            'tm_yday',
            'tm_isdst',
        ];

        if ($associative) {
            foreach ($keys as $i => $key) {
                self::hashSetLong($ht, $key, $values[$i]);
            }
        } else {
            foreach ($values as $i => $value) {
                $ht->addIndex($i, self::intVariable($value));
            }
        }

        return $ht;
    }

    public static function getdate(?int $timestamp = null): HashTable
    {
        return self::dateBreakdown($timestamp, false);
    }

    /**
     * Build date_parse()/date_parse_from_format() return array (#6172).
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
     */
    public static function parseResultToHashTable(array $result): HashTable
    {
        $ht = new HashTable();
        foreach ([
            'year',
            'month',
            'day',
            'hour',
            'minute',
            'second',
        ] as $key) {
            $value = $result[$key];
            if (false === $value) {
                $var = new Variable(Variable::TYPE_BOOLEAN);
                $var->bool(false);
                $ht->add($key, $var);
            } else {
                self::hashSetLong($ht, $key, $value);
            }
        }
        $fraction = $result['fraction'];
        if (false === $fraction) {
            $var = new Variable(Variable::TYPE_BOOLEAN);
            $var->bool(false);
            $ht->add('fraction', $var);
        } else {
            $var = new Variable(Variable::TYPE_FLOAT);
            $var->float((float) $fraction);
            $ht->add('fraction', $var);
        }
        // php-src zval_from_error_container(): warning_count → warnings → error_count → errors (#25485)
        self::hashSetLong($ht, 'warning_count', $result['warning_count']);
        $warnings = new HashTable();
        foreach ($result['warnings'] as $pos => $message) {
            self::hashSetString($warnings, (string) $pos, $message);
        }
        $warningsVar = new Variable();
        $warningsVar->array($warnings);
        $ht->add('warnings', $warningsVar);

        self::hashSetLong($ht, 'error_count', $result['error_count']);
        $errors = new HashTable();
        foreach ($result['errors'] as $pos => $message) {
            self::hashSetString($errors, (string) $pos, $message);
        }
        $errorsVar = new Variable();
        $errorsVar->array($errors);
        $ht->add('errors', $errorsVar);

        // php-src date_parse: is_localtime after error container, then zone / relative (#25485)
        self::hashSetBool($ht, 'is_localtime', $result['is_localtime']);
        // php-src php_date.c — zone keys follow is_localtime; tz_abbr before tz_id (#25487).
        if (isset($result['zone_type'])) {
            self::hashSetLong($ht, 'zone_type', $result['zone_type']);
        }
        if (isset($result['zone'])) {
            self::hashSetLong($ht, 'zone', $result['zone']);
        }
        if (isset($result['is_dst'])) {
            self::hashSetBool($ht, 'is_dst', $result['is_dst']);
        }
        if (isset($result['tz_abbr'])) {
            self::hashSetString($ht, 'tz_abbr', $result['tz_abbr']);
        }
        if (isset($result['tz_id'])) {
            self::hashSetString($ht, 'tz_id', $result['tz_id']);
        }

        if (isset($result['relative']) && \is_array($result['relative'])) {
            $relative = new HashTable();
            foreach (['year', 'month', 'day', 'hour', 'minute', 'second', 'weekday'] as $relKey) {
                self::hashSetLong($relative, $relKey, (int) $result['relative'][$relKey]);
            }
            $relativeVar = new Variable();
            $relativeVar->array($relative);
            $ht->add('relative', $relativeVar);
        }

        return $ht;
    }

    /**
     * DateTime/DateTimeImmutable::getLastErrors() — warning/error subset (#4660, #9920).
     *
     * php-src: ext/date/php_datetime.c — PHP_METHOD(DateTime, getLastErrors)
     *
     * @param array{
     *   warning_count: int,
     *   warnings: array<int, string>,
     *   error_count: int,
     *   errors: array<int, string>
     * } $result
     */
    public static function lastErrorsToHashTable(array $result): HashTable
    {
        $ht = new HashTable();
        // php-src zval_from_error_container(): warning_count → warnings → error_count → errors (#25485)
        self::hashSetLong($ht, 'warning_count', $result['warning_count']);
        $warnings = new HashTable();
        foreach ($result['warnings'] as $pos => $message) {
            self::hashSetString($warnings, (string) $pos, $message);
        }
        $warningsVar = new Variable();
        $warningsVar->array($warnings);
        $ht->add('warnings', $warningsVar);

        self::hashSetLong($ht, 'error_count', $result['error_count']);
        $errors = new HashTable();
        foreach ($result['errors'] as $pos => $message) {
            self::hashSetString($errors, (string) $pos, $message);
        }
        $errorsVar = new Variable();
        $errorsVar->array($errors);
        $ht->add('errors', $errorsVar);

        return $ht;
    }

    /**
     * UTC getdate()-shaped breakdown (internal helper; not exported — php-src has no gmgetdate, #24608).
     */
    public static function gmgetdate(?int $timestamp = null): HashTable
    {
        return self::dateBreakdown($timestamp, true);
    }

    /**
     * checkdate() — calendar validation (php-src ext/standard/datetime.c PHP_FUNCTION(checkdate), #3292).
     */
    public static function checkdate(int $month, int $day, int $year): bool
    {
        return VmCheckdate::validate($month, $day, $year);
    }

    /** date_default_timezone_get() — active default timezone id (#3292). */
    public static function defaultTimezoneGet(): string
    {
        return self::$defaultTimezone;
    }

    /**
     * date_default_timezone_set() — validate and store default timezone (#3292).
     *
     * @return bool false when the identifier is unknown (Zend emits E_NOTICE)
     */
    public static function tryDefaultTimezoneSet(string $timezone): bool
    {
        if (!VmDateTimeNative::timezoneIdIsValid($timezone)) {
            return false;
        }
        self::$defaultTimezone = VmDateTimeNative::validateTimezoneId($timezone);

        return true;
    }

    /**
     * mktime() — local-time timestamp from parts (php-src ext/date/php_date.c PHP_FUNCTION(mktime), #3292).
     */
    public static function mktime(
        int $hour,
        ?int $minute = null,
        ?int $second = null,
        ?int $month = null,
        ?int $day = null,
        ?int $year = null
    ): int|false {
        if (null === $minute) {
            $tm = self::localtime(self::time());
            if (null === $tm) {
                return false;
            }
            $minute = self::tmInt($tm, 'tm_min');
            $second = self::tmInt($tm, 'tm_sec');
            $month = self::tmInt($tm, 'tm_mon') + 1;
            $day = self::tmInt($tm, 'tm_mday');
            $year = self::tmInt($tm, 'tm_year') + 1900;
        } else {
            $tm = self::localtime(self::time());
            if (null === $tm) {
                return false;
            }
            if (null === $second) {
                $second = self::tmInt($tm, 'tm_sec');
            }
            if (null === $month) {
                $month = self::tmInt($tm, 'tm_mon') + 1;
            }
            if (null === $day) {
                $day = self::tmInt($tm, 'tm_mday');
            }
            if (null === $year) {
                $year = self::tmInt($tm, 'tm_year') + 1900;
            }
        }

        return VmDatePure::mktime($hour, $minute, $second, $month, $day, $year);
    }

    /**
     * gmmktime() — UTC mktime (php-src ext/date/php_date.c PHP_FUNCTION(gmmktime), #7001).
     */
    public static function gmmktime(
        int $hour,
        ?int $minute = null,
        ?int $second = null,
        ?int $month = null,
        ?int $day = null,
        ?int $year = null
    ): int|false {
        if (null === $minute) {
            $tm = self::gmtime(self::time());
            if (null === $tm) {
                return false;
            }
            $minute = self::tmInt($tm, 'tm_min');
            $second = self::tmInt($tm, 'tm_sec');
            $month = self::tmInt($tm, 'tm_mon') + 1;
            $day = self::tmInt($tm, 'tm_mday');
            $year = self::tmInt($tm, 'tm_year') + 1900;
        } else {
            $tm = self::gmtime(self::time());
            if (null === $tm) {
                return false;
            }
            if (null === $second) {
                $second = self::tmInt($tm, 'tm_sec');
            }
            if (null === $month) {
                $month = self::tmInt($tm, 'tm_mon') + 1;
            }
            if (null === $day) {
                $day = self::tmInt($tm, 'tm_mday');
            }
            if (null === $year) {
                $year = self::tmInt($tm, 'tm_year') + 1900;
            }
        }

        return VmDatePure::gmmktime($hour, $minute, $second, $month, $day, $year);
    }

    private static function dateBreakdown(?int $timestamp, bool $gmt): HashTable
    {
        $ts = $timestamp ?? self::time();
        $tm = $gmt ? self::gmtime($ts) : self::localtime($ts);
        $ht = new HashTable();
        if (null === $tm) {
            return $ht;
        }

        self::hashSetLong($ht, 'seconds', self::tmInt($tm, 'tm_sec'));
        self::hashSetLong($ht, 'minutes', self::tmInt($tm, 'tm_min'));
        self::hashSetLong($ht, 'hours', self::tmInt($tm, 'tm_hour'));
        self::hashSetLong($ht, 'mday', self::tmInt($tm, 'tm_mday'));
        self::hashSetLong($ht, 'wday', self::tmInt($tm, 'tm_wday'));
        self::hashSetLong($ht, 'mon', self::tmInt($tm, 'tm_mon') + 1);
        self::hashSetLong($ht, 'year', self::tmInt($tm, 'tm_year') + 1900);
        self::hashSetLong($ht, 'yday', self::tmInt($tm, 'tm_yday'));
        self::hashSetString($ht, 'weekday', self::weekdayName(self::tmInt($tm, 'tm_wday')));
        self::hashSetString($ht, 'month', self::monthName(self::tmInt($tm, 'tm_mon')));
        $ht->addIndex(0, self::intVariable($ts));

        return $ht;
    }

    public static function gettimeofdayFloat(): float
    {
        $tv = self::readTimeval();

        return (float) $tv['sec'] + (float) $tv['usec'] / 1_000_000.0;
    }

    /**
     * Wall-clock sec/usec for uniqid() and other builtins (#8402, #13765).
     *
     * @return array{sec: int, usec: int}
     */
    public static function wallClock(): array
    {
        return self::readTimeval();
    }

    public static function gettimeofdayArray(): HashTable
    {
        $parts = VmDatePure::gettimeofdayParts();
        $ht = new HashTable();
        self::hashSetLong($ht, 'sec', $parts['sec']);
        self::hashSetLong($ht, 'usec', $parts['usec']);
        self::hashSetLong($ht, 'minuteswest', $parts['minuteswest']);
        self::hashSetLong($ht, 'dsttime', $parts['dsttime']);

        return $ht;
    }

    private static function formatDateTime(string $format, int $timestamp, bool $gmt): string
    {
        $tm = $gmt ? self::gmtime($timestamp) : self::localtime($timestamp);
        if (null === $tm) {
            return '';
        }

        $tzName = $gmt ? 'UTC' : self::$defaultTimezone;
        $offset = $gmt ? 0 : VmDateTimeNative::timezoneOffsetSeconds($tzName, $timestamp);

        return self::formatDateTimeFromTm($format, $timestamp, 0, $tm, $offset, $tzName);
    }

    /**
     * @param array<string, int>|\FFI\CData $tm struct tm from localtime_r/gmtime_r or VmDatePure
     */
    public static function formatDateTimeFromTm(
        string $format,
        int $timestamp,
        int $microsecond,
        array|\FFI\CData $tm,
        int $offsetSeconds,
        string $tzName
    ): string {
        $year = self::tmInt($tm, 'tm_year') + 1900;
        $month = self::tmInt($tm, 'tm_mon') + 1;
        $day = self::tmInt($tm, 'tm_mday');
        $hour = self::tmInt($tm, 'tm_hour');
        $minute = self::tmInt($tm, 'tm_min');
        $second = self::tmInt($tm, 'tm_sec');
        $wday = self::tmInt($tm, 'tm_wday');
        $yday = self::tmInt($tm, 'tm_yday');
        $isdst = self::tmInt($tm, 'tm_isdst');
        $hour12 = self::hour12($hour);
        $isoWeek = self::isoWeek($year, $month, $day);
        $isoYear = self::isoYear($year, $month, $day);

        $out = '';
        $len = \strlen($format);
        for ($i = 0; $i < $len; ++$i) {
            $ch = $format[$i];
            if ('\\' === $ch && $i + 1 < $len) {
                $out .= $format[++$i];

                continue;
            }
            switch ($ch) {
                case 'U':
                    $out .= (string) $timestamp;

                    break;
                case 'u':
                    $out .= self::padInt($microsecond, 6);

                    break;
                case 'Y':
                    $out .= self::padInt($year, 4);

                    break;
                case 'y':
                    $out .= self::padInt($year % 100, 2);

                    break;
                case 'o':
                    $out .= (string) $isoYear;

                    break;
                case 'm':
                    $out .= self::padInt($month, 2);

                    break;
                case 'n':
                    $out .= (string) $month;

                    break;
                case 'd':
                    $out .= self::padInt($day, 2);

                    break;
                case 'j':
                    $out .= (string) $day;

                    break;
                case 'H':
                    $out .= self::padInt($hour, 2);

                    break;
                case 'G':
                    $out .= (string) $hour;

                    break;
                case 'h':
                    $out .= self::padInt($hour12, 2);

                    break;
                case 'g':
                    $out .= (string) $hour12;

                    break;
                case 'i':
                    $out .= self::padInt($minute, 2);

                    break;
                case 's':
                    $out .= self::padInt($second, 2);

                    break;
                case 'a':
                    $out .= $hour < 12 ? 'am' : 'pm';

                    break;
                case 'A':
                    $out .= $hour < 12 ? 'AM' : 'PM';

                    break;
                case 'S':
                    $out .= self::ordinalSuffix($day);

                    break;
                case 'w':
                    $out .= (string) $wday;

                    break;
                case 'N':
                    $out .= (string) (0 === $wday ? 7 : $wday);

                    break;
                case 'z':
                    $out .= (string) $yday;

                    break;
                case 't':
                    $out .= (string) self::daysInMonth($year, $month);

                    break;
                case 'L':
                    $out .= self::isLeapYear($year) ? '1' : '0';

                    break;
                case 'W':
                    $out .= self::padInt($isoWeek, 2);

                    break;
                case 'B':
                    $out .= self::padInt(self::swatchBeat($timestamp), 3);

                    break;
                case 'I':
                    $out .= $isdst > 0 ? '1' : '0';

                    break;
                case 'D':
                    $out .= self::shortWeekdayName($wday);

                    break;
                case 'l':
                    $out .= self::weekdayName($wday);

                    break;
                case 'M':
                    $out .= self::shortMonthName($month);

                    break;
                case 'F':
                    $out .= self::monthName($month - 1);

                    break;
                case 'c':
                    $out .= self::padInt($year, 4).'-'
                        .self::padInt($month, 2).'-'
                        .self::padInt($day, 2).'T'
                        .self::padInt($hour, 2).':'
                        .self::padInt($minute, 2).':'
                        .self::padInt($second, 2)
                        .self::formatOffsetColon($offsetSeconds);

                    break;
                case 'r':
                    $out .= self::shortWeekdayName($wday).', '
                        .self::padInt($day, 2).' '
                        .self::shortMonthName($month).' '
                        .self::padInt($year, 4).' '
                        .self::padInt($hour, 2).':'
                        .self::padInt($minute, 2).':'
                        .self::padInt($second, 2).' '
                        .self::formatOffsetCompact($offsetSeconds);

                    break;
                case 'e':
                    $out .= $tzName;

                    break;
                case 'T':
                    $out .= self::timezoneAbbreviation($tzName, $timestamp, $offsetSeconds);

                    break;
                case 'Z':
                    $out .= (string) $offsetSeconds;

                    break;
                case 'O':
                    $out .= self::formatOffsetCompact($offsetSeconds);

                    break;
                case 'P':
                    $out .= self::formatOffsetColon($offsetSeconds);

                    break;
                default:
                    $out .= $ch;
            }
            if (\strlen($out) >= self::FORMAT_OUT_BYTES) {
                break;
            }
        }

        return $out;
    }

    private static function ordinalSuffix(int $day): string
    {
        if ($day >= 11 && $day <= 13) {
            return 'th';
        }

        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    private static function shortWeekdayName(int $wday): string
    {
        static $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        return $names[$wday] ?? 'Sun';
    }

    private static function shortMonthName(int $month): string
    {
        static $names = [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dec',
        ];

        return $names[$month] ?? 'Jan';
    }

    private static function formatOffsetCompact(int $offsetSeconds): string
    {
        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $abs = \abs($offsetSeconds);
        $hours = (int) \floor($abs / 3600);
        $minutes = (int) \floor(($abs % 3600) / 60);

        return $sign.self::padInt($hours, 2).self::padInt($minutes, 2);
    }

    private static function formatOffsetColon(int $offsetSeconds): string
    {
        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $abs = \abs($offsetSeconds);
        $hours = (int) \floor($abs / 3600);
        $minutes = (int) \floor(($abs % 3600) / 60);

        return $sign.self::padInt($hours, 2).':'.self::padInt($minutes, 2);
    }

    private static function timezoneAbbreviation(string $tzName, int $timestamp, int $offsetSeconds): string
    {
        if ('UTC' === $tzName) {
            return 'UTC';
        }

        if (null !== VmDateTimeNative::parseNumericTimezoneOffset($tzName)) {
            return self::formatGmtOffsetAbbreviation($offsetSeconds);
        }

        $abbr = self::libcStrftime('%Z', $timestamp, false);
        if ('' !== $abbr) {
            return $abbr;
        }

        return $tzName;
    }

    /** php-src timelib offset-zone abbreviation (e.g. GMT+0400 for +04:00). */
    private static function formatGmtOffsetAbbreviation(int $offsetSeconds): string
    {
        if (0 === $offsetSeconds) {
            return 'GMT';
        }
        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $abs = \abs($offsetSeconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);

        return 'GMT'.$sign.self::padInt($hours, 2).self::padInt($minutes, 2);
    }

    private static function hour12(int $hour): int
    {
        $h = $hour % 12;

        return 0 === $h ? 12 : $h;
    }

    private static function isLeapYear(int $year): bool
    {
        return (0 === $year % 4 && 0 !== $year % 100) || 0 === $year % 400;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        static $days = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        if ($month < 1 || $month > 12) {
            return 0;
        }
        if (2 === $month && self::isLeapYear($year)) {
            return 29;
        }

        return $days[$month - 1];
    }

    /**
     * Swatch Internet Time beats (php-src ext/date/php_date.c format 'B').
     */
    private static function swatchBeat(int $timestamp): int
    {
        $retval = ($timestamp - ($timestamp - ((($timestamp % 86400) + 3600) % 86400))) * 10;
        if ($retval < 0) {
            $retval += 864000;
        }

        return intdiv($retval, 864) % 1000;
    }

    /** ISO-8601 week number (php-src timelib_isoweek_from_date subset). */
    private static function isoWeek(int $year, int $month, int $day): int
    {
        return self::isoWeekAndYear($year, $month, $day)[0];
    }

    /** ISO-8601 year (php-src timelib_isoweek_from_date subset). */
    private static function isoYear(int $year, int $month, int $day): int
    {
        return self::isoWeekAndYear($year, $month, $day)[1];
    }

    /**
     * @return array{0: int, 1: int} week number, ISO year
     */
    private static function isoWeekAndYear(int $year, int $month, int $day): array
    {
        $timestamp = self::gmmktime(12, 0, 0, $month, $day, $year);
        if (false === $timestamp) {
            return [0, $year];
        }
        $week = (int) self::libcStrftime('%V', $timestamp, true);
        $isoYear = (int) self::libcStrftime('%G', $timestamp, true);
        if ($week > 0 && $isoYear > 0) {
            return [$week, $isoYear];
        }

        return self::isoWeekAndYearFallback($year, $month, $day);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function isoWeekAndYearFallback(int $year, int $month, int $day): array
    {
        $a = (int) \floor((14 - $month) / 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;
        $jd = $day
            + (int) \floor((153 * $m + 2) / 5)
            + 365 * $y
            + (int) \floor($y / 4)
            - (int) \floor($y / 100)
            + (int) \floor($y / 400)
            - 32045;
        $d4 = $jd + 31739 - ($jd % 7);
        $d1 = $d4 % 146097;
        $week = (int) \floor($d1 / 7) - (int) \floor($d1 / 36524) + (int) \floor($d1 / 1461);
        $d3 = $d1 % 36524;
        $week += (int) \floor($d3 / 1461) - (int) \floor($d3 / 365) + (int) \floor($d3 / 7);
        $d5 = $d3 % 365;
        $week += (int) \floor($d5 / 7);
        $isoYear = (int) \floor($d4 / 146097) * 100
            + (int) \floor($d1 / 36524)
            + (int) \floor($d3 / 1461)
            + (int) \floor($d5 / 365)
            + (int) \floor($d5 / 7);

        return [$week, $isoYear];
    }

    private static function padInt(int $value, int $width): string
    {
        $s = (string) $value;
        if (\strlen($s) >= $width) {
            return $s;
        }

        return \str_repeat('0', $width - \strlen($s)).$s;
    }

    /**
     * @return array{sec: int, usec: int}
     */
    private static function readTimeval(): array
    {
        return VmDatePure::readTimeval();
    }

    private static function libcStrftime(string $format, int $timestamp, bool $gmt): string|false
    {
        return VmDatePure::strftime($format, $timestamp, $gmt);
    }

    /**
     * @return array<string, int>|null
     */
    private static function localtime(int $timestamp): ?array
    {
        return VmDatePure::localtime($timestamp);
    }

    /**
     * @return array<string, int>|null
     */
    private static function gmtime(int $timestamp): ?array
    {
        return VmDatePure::gmtime($timestamp);
    }

    /**
     * @param array<string, int>|\FFI\CData $tm
     */
    private static function tmInt(array|\FFI\CData $tm, string $field): int
    {
        if ($tm instanceof \FFI\CData) {
            return (int) $tm->$field;
        }

        return (int) ($tm[$field] ?? 0);
    }

    private static function weekdayName(int $wday): string
    {
        static $names = [
            'Sunday',
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Saturday',
        ];

        return $names[$wday] ?? 'Sunday';
    }

    private static function monthName(int $mon): string
    {
        static $names = [
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

        return $names[$mon] ?? 'January';
    }

    private static function hashSetLong(HashTable $ht, string $key, int $value): void
    {
        $ht->add($key, self::intVariable($value));
    }

    private static function hashSetBool(HashTable $ht, string $key, bool $value): void
    {
        $var = new Variable(Variable::TYPE_BOOLEAN);
        $var->bool($value);
        $ht->add($key, $var);
    }

    private static function hashSetString(HashTable $ht, string $key, string $value): void
    {
        $var = new Variable();
        $var->string($value);
        $ht->add($key, $var);
    }

    private static function intVariable(int $value): Variable
    {
        $var = new Variable(Variable::TYPE_INTEGER);
        $var->int($value);

        return $var;
    }
}
