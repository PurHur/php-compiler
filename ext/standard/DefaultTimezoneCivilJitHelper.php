<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Default-timezone civil helpers for getdate()/idate()/date() tz tokens (#31047, #33956).
 *
 * Reads {@see DefaultTimezoneJitHelper} so NestedJIT thin AOT shares date_default_timezone_set
 * state (#33950 follow-up). Avoids gmdate/preg/ctype and VmDateTimeNative NestedJIT stubs.
 * php-src: ext/date/lib/timelib.c — timelib_unixtime2local; php_format_date tokens T/e/O/P
 */
final class DefaultTimezoneCivilJitHelper
{
    public static function localCivilTimestamp(int $timestamp): int
    {
        $tzName = DefaultTimezoneJitHelper::defaultTimezoneGet();

        return $timestamp + self::offsetSeconds($tzName, $timestamp);
    }

    public static function localIsDst(int $timestamp): int
    {
        $tzName = DefaultTimezoneJitHelper::defaultTimezoneGet();
        if ('UTC' === $tzName || 'Etc/UTC' === $tzName || 'GMT' === $tzName) {
            return 0;
        }
        if ('Europe/Berlin' === $tzName || 'Europe/Paris' === $tzName
            || 'Europe/Amsterdam' === $tzName || 'Europe/London' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 1 : 0;
        }
        if ('America/New_York' === $tzName) {
            return self::inUsEasternSummer2022to2027($timestamp) ? 1 : 0;
        }

        return 0;
    }

    /** date() token T under the active default zone (#33956). */
    public static function formatTokenT(int $timestamp): string
    {
        $tzName = DefaultTimezoneJitHelper::defaultTimezoneGet();
        if ('UTC' === $tzName || 'Etc/UTC' === $tzName || 'GMT' === $tzName || 'Z' === $tzName) {
            return 'UTC';
        }
        if ('Europe/Berlin' === $tzName || 'Europe/Paris' === $tzName || 'Europe/Amsterdam' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 'CEST' : 'CET';
        }
        if ('Europe/London' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 'BST' : 'GMT';
        }
        if ('America/New_York' === $tzName) {
            return self::inUsEasternSummer2022to2027($timestamp) ? 'EDT' : 'EST';
        }
        if ('Asia/Tokyo' === $tzName) {
            return 'JST';
        }

        return $tzName;
    }

    public static function formatTokenE(): string
    {
        return DefaultTimezoneJitHelper::defaultTimezoneGet();
    }

    public static function formatTokenO(int $timestamp): string
    {
        $off = self::offsetSeconds(DefaultTimezoneJitHelper::defaultTimezoneGet(), $timestamp);
        if (0 === $off) {
            return '+0000';
        }
        if (3600 === $off) {
            return '+0100';
        }
        if (7200 === $off) {
            return '+0200';
        }
        if (-14400 === $off) {
            return '-0400';
        }
        if (-18000 === $off) {
            return '-0500';
        }
        if (32400 === $off) {
            return '+0900';
        }

        return '+0000';
    }

    public static function formatTokenP(int $timestamp): string
    {
        $off = self::offsetSeconds(DefaultTimezoneJitHelper::defaultTimezoneGet(), $timestamp);
        if (0 === $off) {
            return '+00:00';
        }
        if (3600 === $off) {
            return '+01:00';
        }
        if (7200 === $off) {
            return '+02:00';
        }
        if (-14400 === $off) {
            return '-04:00';
        }
        if (-18000 === $off) {
            return '-05:00';
        }
        if (32400 === $off) {
            return '+09:00';
        }

        return '+00:00';
    }

    /**
     * Single NestedJIT entry for free date() T/e/O/P (#33956 follow-up).
     *
     * Four separate formatToken* helpers inlined into one user function SIGSEGV
     * after the third unique call (T+e+O then P) — #33943 combined repro.
     */
    public static function formatTimezoneToken(string $token, int $timestamp): string
    {
        if ('T' === $token) {
            return self::formatTokenT($timestamp);
        }
        if ('e' === $token) {
            return self::formatTokenE();
        }
        if ('O' === $token) {
            return self::formatTokenO($timestamp);
        }
        if ('P' === $token) {
            return self::formatTokenP($timestamp);
        }

        return '';
    }

    private static function offsetSeconds(string $tzName, int $timestamp): int
    {
        if ('UTC' === $tzName || 'Etc/UTC' === $tzName || 'GMT' === $tzName || 'Z' === $tzName) {
            return 0;
        }
        if ('Europe/Berlin' === $tzName || 'Europe/Paris' === $tzName || 'Europe/Amsterdam' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 7200 : 3600;
        }
        if ('Europe/London' === $tzName) {
            return self::inEuSummer2022to2027($timestamp) ? 3600 : 0;
        }
        if ('America/New_York' === $tzName) {
            return self::inUsEasternSummer2022to2027($timestamp) ? -14400 : -18000;
        }
        if ('Asia/Tokyo' === $tzName) {
            return 32400;
        }

        return 0;
    }

    /** Absolute unix windows — NestedJIT large `%` is unreliable. */
    private static function inEuSummer2022to2027(int $timestamp): bool
    {
        return ($timestamp >= 1648771200 && $timestamp < 1666872000)
            || ($timestamp >= 1680220800 && $timestamp < 1698321600)
            || ($timestamp >= 1711843200 && $timestamp < 1729994400)
            || ($timestamp >= 1743292800 && $timestamp < 1761444000)
            || ($timestamp >= 1774742400 && $timestamp < 1792893600)
            || ($timestamp >= 1806192000 && $timestamp < 1824343200);
    }

    private static function inUsEasternSummer2022to2027(int $timestamp): bool
    {
        return ($timestamp >= 1647158400 && $timestamp < 1667718000)
            || ($timestamp >= 1678608000 && $timestamp < 1699167600)
            || ($timestamp >= 1710057600 && $timestamp < 1730617200)
            || ($timestamp >= 1741507200 && $timestamp < 1762066800)
            || ($timestamp >= 1772956800 && $timestamp < 1793516400)
            || ($timestamp >= 1804406400 && $timestamp < 1824966000);
    }
}
