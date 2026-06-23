<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * timezone_offset_get() offset math for compiled JIT/AOT modules (#9452, php-in-PHP).
 *
 * SSOT: {@see VmDateTimeNative::timezoneOffsetSeconds()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_offset_get)
 */
final class TimezoneOffsetJitHelper
{
    public static function offsetSeconds(string $tzName, int $timestamp): int
    {
        return VmDateTimeNative::timezoneOffsetSeconds($tzName, $timestamp);
    }
}
