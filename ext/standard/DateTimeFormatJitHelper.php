<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * DateTime/DateTimeImmutable::format() for compiled JIT/AOT modules (#4043, php-in-PHP).
 *
 * SSOT: {@see VmDateTimeNative::format()}
 * php-src: ext/date/php_datetime.c — zim_DateTime_format
 */
final class DateTimeFormatJitHelper
{
    public static function formatStateArgv(string $format, int $timestamp, int $microsecond, string $tzName): string
    {
        return VmDateTimeNative::format($timestamp, $microsecond, $tzName, $format);
    }
}
