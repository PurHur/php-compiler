<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure-PHP date/time libc replacements via host builtins (#13765, php-in-PHP).
 *
 * php-src: ext/standard/datetime.c — time, mktime, strptime, strftime, gettimeofday
 */
final class VmDatePure
{
    /** @var array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int} */
    public static function tmPartsFromGetdate(array $d): array
    {
        return [
            'tm_sec' => (int) ($d['seconds'] ?? 0),
            'tm_min' => (int) ($d['minutes'] ?? 0),
            'tm_hour' => (int) ($d['hours'] ?? 0),
            'tm_mday' => (int) ($d['mday'] ?? 0),
            'tm_mon' => (int) ($d['mon'] ?? 1) - 1,
            'tm_year' => (int) ($d['year'] ?? 1970) - 1900,
            'tm_wday' => (int) ($d['wday'] ?? 0),
            'tm_yday' => (int) ($d['yday'] ?? 0),
            'tm_isdst' => (int) ($d['isdst'] ?? 0),
        ];
    }

    public static function available(): bool
    {
        return \function_exists('time') && \function_exists('getdate');
    }

    public static function time(): int
    {
        if (!\function_exists('time')) {
            return 0;
        }

        return (int) \time();
    }

    /** @return array{sec: int, usec: int} */
    public static function readTimeval(): array
    {
        if (\function_exists('gettimeofday')) {
            $tv = \gettimeofday();
            if (\is_array($tv)) {
                return [
                    'sec' => (int) ($tv['sec'] ?? 0),
                    'usec' => (int) ($tv['usec'] ?? 0),
                ];
            }
        }

        if (\function_exists('microtime')) {
            $parts = \explode(' ', (string) \microtime());

            return [
                'sec' => (int) ($parts[1] ?? 0),
                'usec' => (int) \round((float) ($parts[0] ?? 0) * 1_000_000),
            ];
        }

        return ['sec' => 0, 'usec' => 0];
    }

    /**
     * @return array{sec: int, usec: int, minuteswest: int, dsttime: int}
     */
    public static function gettimeofdayParts(): array
    {
        $tv = self::readTimeval();
        $minuteswest = 0;
        $dsttime = 0;
        if (\function_exists('date')) {
            $minuteswest = -(int) \round((int) \date('Z') / 60);
            $dsttime = (int) (0 !== (int) \date('I'));
        }

        return [
            'sec' => $tv['sec'],
            'usec' => $tv['usec'],
            'minuteswest' => $minuteswest,
            'dsttime' => $dsttime,
        ];
    }

    /**
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}|null
     */
    public static function localtime(int $timestamp): ?array
    {
        if (!\function_exists('getdate')) {
            return null;
        }
        $d = @\getdate($timestamp);
        if (!\is_array($d)) {
            return null;
        }

        return self::tmPartsFromGetdate($d);
    }

    /**
     * @return array{tm_sec:int,tm_min:int,tm_hour:int,tm_mday:int,tm_mon:int,tm_year:int,tm_wday:int,tm_yday:int,tm_isdst:int}|null
     */
    public static function gmtime(int $timestamp): ?array
    {
        if (!\function_exists('gmdate')) {
            return null;
        }

        return [
            'tm_sec' => (int) \gmdate('s', $timestamp),
            'tm_min' => (int) \gmdate('i', $timestamp),
            'tm_hour' => (int) \gmdate('G', $timestamp),
            'tm_mday' => (int) \gmdate('j', $timestamp),
            'tm_mon' => (int) \gmdate('n', $timestamp) - 1,
            'tm_year' => (int) \gmdate('Y', $timestamp) - 1900,
            'tm_wday' => (int) \gmdate('w', $timestamp),
            'tm_yday' => (int) \gmdate('z', $timestamp),
            'tm_isdst' => 0,
        ];
    }

    public static function mktime(
        int $hour,
        int $minute,
        int $second,
        int $month,
        int $day,
        int $year
    ): int|false {
        if (!\function_exists('mktime')) {
            return false;
        }
        $result = @\mktime($hour, $minute, $second, $month, $day, $year);

        return false === $result ? false : (int) $result;
    }

    public static function gmmktime(
        int $hour,
        int $minute,
        int $second,
        int $month,
        int $day,
        int $year
    ): int|false {
        if (!\function_exists('gmmktime')) {
            return false;
        }
        $result = @\gmmktime($hour, $minute, $second, $month, $day, $year);

        return false === $result ? false : (int) $result;
    }

    /** @return array<string, mixed>|false */
    public static function strptimeArray(string $date, string $format): array|false
    {
        if (!\function_exists('strptime')) {
            return false;
        }
        $parsed = @\strptime($date, $format);
        if (!\is_array($parsed)) {
            return false;
        }

        return $parsed;
    }

    public static function strftime(string $format, int $timestamp, bool $gmt): string
    {
        if (!\function_exists('strftime')) {
            return '';
        }
        if ($gmt && \function_exists('gmstrftime')) {
            $text = @\gmstrftime($format, $timestamp);

            return \is_string($text) ? $text : '';
        }
        $text = @\strftime($format, $timestamp);

        return \is_string($text) ? $text : '';
    }

    /** Push default timezone for host date/mktime wrappers in VmDateTimeNative::withTimezone (#13857). */
    public static function pushProcessTimezone(string $tzName): string
    {
        $previous = \date_default_timezone_get();
        \date_default_timezone_set($tzName);

        return $previous;
    }

    public static function popProcessTimezone(string $previous): void
    {
        \date_default_timezone_set($previous);
    }
}
