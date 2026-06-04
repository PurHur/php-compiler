<?php

declare(strict_types=1);

/**
 * VM date/time helpers without host Zend time()/date() (issue #5045).
 *
 * php-src: ext/date/php_date.c — time, date, gmdate, microtime, getdate.
 * JIT/AOT: JitDate.php, phpc_microtime.c, StringDateTime (__compiler_format_datetime).
 */
namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

final class VmDate
{
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
        $ffi = self::ffi();
        if (null !== $ffi) {
            return (int) $ffi->getpid();
        }

        return (int) \getmypid();
    }

    /** getmygrgid() — real group id (ext/standard/basic_functions.c, #3611). */
    public static function getmygrgid(): int
    {
        if (\function_exists('posix_getgid')) {
            return (int) \posix_getgid();
        }
        if (\function_exists('getgid')) {
            return (int) \getgid();
        }

        throw new \LogicException('getmygrgid() requires POSIX support in this compiler build');
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
        $stat = @\stat($path);
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

    public static function getdate(?int $timestamp = null): HashTable
    {
        $ts = $timestamp ?? self::time();
        $tm = self::localtime($ts);
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
typedef int pid_t;
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
pid_t getpid(void);
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
