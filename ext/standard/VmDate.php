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
