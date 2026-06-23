<?php

declare(strict_types=1);

/**
 * VM date/time helpers without host Zend time()/date() (issue #5045).
 *
 * php-src: ext/date/php_date.c — time, date, gmdate, microtime, getdate.
 * JIT/AOT: JitDate.php, StringMicrotime/StringGettimeofday LLVM, StringDateTime (__compiler_format_datetime).
 */
namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
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

    private static ?\FFI $ffi = null;

    public static function time(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return 0;
        }

        return (int) $ffi->time(null);
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
     * timezone_version_get() — Olson/tzdata version sentinel (ext/date/php_date.c, #6832, #8032).
     *
     * PHP-owned: return php-src `0.system` when no bundled timelib DB is linked (self-host/AOT).
     */
    public static function timezone_version_get(): string
    {
        return '0.system';
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
    public static function strftime(string $format, ?int $timestamp = null): string
    {
        return self::libcStrftime($format, $timestamp ?? self::time(), false);
    }

    public static function gmstrftime(string $format, ?int $timestamp = null): string
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
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }
        $tm = $ffi->new('struct tm');
        $tm->tm_sec = 0;
        $tm->tm_min = 0;
        $tm->tm_hour = 0;
        $tm->tm_mday = 0;
        $tm->tm_mon = 0;
        $tm->tm_year = 0;
        $tm->tm_wday = 0;
        $tm->tm_yday = 0;
        $tm->tm_isdst = 0;
        $rest = $ffi->strptime($date, $format, \FFI::addr($tm));
        if (null === $rest) {
            return false;
        }
        $ht = new HashTable();
        self::hashSetLong($ht, 'tm_sec', (int) $tm->tm_sec);
        self::hashSetLong($ht, 'tm_min', (int) $tm->tm_min);
        self::hashSetLong($ht, 'tm_hour', (int) $tm->tm_hour);
        self::hashSetLong($ht, 'tm_mday', (int) $tm->tm_mday);
        self::hashSetLong($ht, 'tm_mon', (int) $tm->tm_mon);
        self::hashSetLong($ht, 'tm_year', (int) $tm->tm_year);
        self::hashSetLong($ht, 'tm_wday', (int) $tm->tm_wday);
        self::hashSetLong($ht, 'tm_yday', (int) $tm->tm_yday);
        self::hashSetString($ht, 'unparsed', \FFI::string($rest));

        return $ht;
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
     * @return int|array{0: int, 1: int}
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

        $sec = (int) $tm->tm_sec;
        $min = (int) $tm->tm_min;
        $hour = (int) $tm->tm_hour;
        $mday = (int) $tm->tm_mday;
        $mon = (int) $tm->tm_mon + 1;
        $year = (int) $tm->tm_year + 1900;
        $wday = (int) $tm->tm_wday;
        $yday = (int) $tm->tm_yday;
        $isdst = (int) $tm->tm_isdst;

        return match ($formatChar) {
            'B' => self::swatchBeat($hour, $min, $sec, $wday),
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
            (int) $tm->tm_sec,
            (int) $tm->tm_min,
            (int) $tm->tm_hour,
            (int) $tm->tm_mday,
            (int) $tm->tm_mon,
            (int) $tm->tm_year,
            (int) $tm->tm_wday,
            (int) $tm->tm_yday,
            (int) $tm->tm_isdst,
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
        self::hashSetLong($ht, 'warning_count', $result['warning_count']);
        self::hashSetLong($ht, 'error_count', $result['error_count']);
        self::hashSetBool($ht, 'is_localtime', $result['is_localtime']);

        $warnings = new HashTable();
        foreach ($result['warnings'] as $pos => $message) {
            self::hashSetString($warnings, (string) $pos, $message);
        }
        $warningsVar = new Variable();
        $warningsVar->array($warnings);
        $ht->add('warnings', $warningsVar);

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
        self::hashSetLong($ht, 'warning_count', $result['warning_count']);
        self::hashSetLong($ht, 'error_count', $result['error_count']);

        $warnings = new HashTable();
        foreach ($result['warnings'] as $pos => $message) {
            self::hashSetString($warnings, (string) $pos, $message);
        }
        $warningsVar = new Variable();
        $warningsVar->array($warnings);
        $ht->add('warnings', $warningsVar);

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
     * gmgetdate() — UTC getdate() breakdown (php-src userland pattern; pairs #6706, #7001).
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
            $minute = (int) $tm->tm_min;
            $second = (int) $tm->tm_sec;
            $month = (int) $tm->tm_mon + 1;
            $day = (int) $tm->tm_mday;
            $year = (int) $tm->tm_year + 1900;
        } else {
            $second ??= 0;
            $month ??= 0;
            $day ??= 0;
            $year ??= 0;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $tmStruct = $ffi->new('struct tm');
        $tmStruct->tm_sec = $second;
        $tmStruct->tm_min = $minute;
        $tmStruct->tm_hour = $hour;
        $tmStruct->tm_mday = $day;
        $tmStruct->tm_mon = $month - 1;
        $tmStruct->tm_year = $year - 1900;
        $tmStruct->tm_isdst = -1;

        $result = (int) $ffi->mktime(\FFI::addr($tmStruct));
        if (-1 === $result) {
            return false;
        }

        return $result;
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
            $minute = (int) $tm->tm_min;
            $second = (int) $tm->tm_sec;
            $month = (int) $tm->tm_mon + 1;
            $day = (int) $tm->tm_mday;
            $year = (int) $tm->tm_year + 1900;
        } else {
            $second ??= 0;
            $month ??= 0;
            $day ??= 0;
            $year ??= 0;
        }

        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        $tmStruct = $ffi->new('struct tm');
        $tmStruct->tm_sec = $second;
        $tmStruct->tm_min = $minute;
        $tmStruct->tm_hour = $hour;
        $tmStruct->tm_mday = $day;
        $tmStruct->tm_mon = $month - 1;
        $tmStruct->tm_year = $year - 1900;
        $tmStruct->tm_isdst = 0;

        $result = (int) $ffi->timegm(\FFI::addr($tmStruct));
        if (-1 === $result) {
            return false;
        }

        return $result;
    }

    private static function dateBreakdown(?int $timestamp, bool $gmt): HashTable
    {
        $ts = $timestamp ?? self::time();
        $tm = $gmt ? self::gmtime($ts) : self::localtime($ts);
        $ht = new HashTable();
        if (null === $tm) {
            return $ht;
        }

        self::hashSetLong($ht, 'seconds', (int) $tm->tm_sec);
        self::hashSetLong($ht, 'minutes', (int) $tm->tm_min);
        self::hashSetLong($ht, 'hours', (int) $tm->tm_hour);
        self::hashSetLong($ht, 'mday', (int) $tm->tm_mday);
        self::hashSetLong($ht, 'wday', (int) $tm->tm_wday);
        self::hashSetLong($ht, 'mon', (int) $tm->tm_mon + 1);
        self::hashSetLong($ht, 'year', (int) $tm->tm_year + 1900);
        self::hashSetLong($ht, 'yday', (int) $tm->tm_yday);
        self::hashSetString($ht, 'weekday', self::weekdayName((int) $tm->tm_wday));
        self::hashSetString($ht, 'month', self::monthName((int) $tm->tm_mon));
        $ht->addIndex(0, self::intVariable($ts));

        return $ht;
    }

    public static function gettimeofdayFloat(): float
    {
        $tv = self::readTimeval();

        return (float) $tv['sec'] + (float) $tv['usec'] / 1_000_000.0;
    }

    /**
     * Wall-clock sec/usec for uniqid() and other builtins — libc FFI only (#8402, pairs #6722).
     *
     * @return array{sec: int, usec: int}
     */
    public static function wallClock(): array
    {
        return self::readTimeval();
    }

    public static function gettimeofdayArray(): HashTable
    {
        $ffi = self::ffi();
        $ht = new HashTable();
        if (null === $ffi) {
            foreach (['sec', 'usec', 'minuteswest', 'dsttime'] as $key) {
                self::hashSetLong($ht, $key, 0);
            }

            return $ht;
        }

        $tv = $ffi->new('struct timeval');
        $tz = $ffi->new('struct timezone');
        if (0 !== (int) $ffi->gettimeofday(\FFI::addr($tv), \FFI::addr($tz))) {
            $tv->tv_sec = 0;
            $tv->tv_usec = 0;
            $tz->tz_minuteswest = 0;
            $tz->tz_dsttime = 0;
        }

        self::hashSetLong($ht, 'sec', (int) $tv->tv_sec);
        self::hashSetLong($ht, 'usec', (int) $tv->tv_usec);
        self::hashSetLong($ht, 'minuteswest', (int) $tz->tz_minuteswest);
        self::hashSetLong($ht, 'dsttime', (int) $tz->tz_dsttime);

        return $ht;
    }

    private static function formatDateTime(string $format, int $timestamp, bool $gmt): string
    {
        $tm = $gmt ? self::gmtime($timestamp) : self::localtime($timestamp);
        if (null === $tm) {
            return '';
        }

        $year = (int) $tm->tm_year + 1900;
        $month = (int) $tm->tm_mon + 1;
        $day = (int) $tm->tm_mday;
        $hour = (int) $tm->tm_hour;
        $minute = (int) $tm->tm_min;
        $second = (int) $tm->tm_sec;

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
                default:
                    $out .= $ch;
            }
            if (\strlen($out) >= self::FORMAT_OUT_BYTES) {
                break;
            }
        }

        return $out;
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

    private static function swatchBeat(int $hour, int $minute, int $second, int $wday): int
    {
        $seconds = ($hour * 3600) + ($minute * 60) + $second;
        $daysFromMonday = ($wday + 6) % 7;
        $total = $seconds + ($daysFromMonday * 86400);

        return (int) \floor($total / 86.4);
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
        $ffi = self::ffi();
        if (null === $ffi) {
            return ['sec' => 0, 'usec' => 0];
        }
        $tv = $ffi->new('struct timeval');
        if (0 !== (int) $ffi->gettimeofday(\FFI::addr($tv), null)) {
            return ['sec' => 0, 'usec' => 0];
        }

        return ['sec' => (int) $tv->tv_sec, 'usec' => (int) $tv->tv_usec];
    }

    private static function libcStrftime(string $format, int $timestamp, bool $gmt): string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return '';
        }
        $tm = $gmt ? self::gmtime($timestamp) : self::localtime($timestamp);
        if (null === $tm) {
            return '';
        }
        $buf = $ffi->new('char['.self::FORMAT_OUT_BYTES.']');
        $len = (int) $ffi->strftime(
            \FFI::addr($buf[0]),
            self::FORMAT_OUT_BYTES,
            $format,
            \FFI::addr($tm)
        );
        if ($len <= 0) {
            return '';
        }

        return \FFI::string($buf, $len);
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
        $tm = $ffi->localtime_r(\FFI::addr($ts), \FFI::addr($buf));

        return null === $tm ? null : $buf;
    }

    private static function gmtime(int $timestamp): ?\FFI\CData
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $ts = $ffi->new('time_t');
        $ts->cdata = $timestamp;
        $buf = $ffi->new('struct tm');
        $tm = $ffi->gmtime_r(\FFI::addr($ts), \FFI::addr($buf));

        return null === $tm ? null : $buf;
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
typedef unsigned long size_t;
struct timeval {
    time_t tv_sec;
    long tv_usec;
};
struct timezone {
    int tz_minuteswest;
    int tz_dsttime;
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
time_t time(time_t *tloc);
int gettimeofday(struct timeval *tv, struct timezone *tz);
struct tm *localtime_r(const time_t *timep, struct tm *result);
struct tm *gmtime_r(const time_t *timep, struct tm *result);
time_t timegm(struct tm *tm);
time_t mktime(struct tm *tm);
size_t strftime(char *s, size_t max, const char *format, const struct tm *tm);
char *strptime(const char *s, const char *format, struct tm *tm);
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
