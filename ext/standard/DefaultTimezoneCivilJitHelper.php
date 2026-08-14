<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Default-timezone civil unix timestamp for getdate()/idate() LLVM paths (#31047).
 *
 * SSOT: {@see VmDate::localtime()} — epoch seconds + active default-zone offset.
 * php-src: ext/date/lib/timelib.c — timelib_unixtime2local
 */
final class DefaultTimezoneCivilJitHelper
{
    public static function localCivilTimestamp(int $timestamp): int
    {
        $tzName = VmDate::defaultTimezoneGet();

        return $timestamp + VmDateTimeNative::timezoneOffsetSeconds($tzName, $timestamp);
    }

    public static function localIsDst(int $timestamp): int
    {
        return VmDateTimeNative::timezoneIsDst(VmDate::defaultTimezoneGet(), $timestamp) ? 1 : 0;
    }
}
