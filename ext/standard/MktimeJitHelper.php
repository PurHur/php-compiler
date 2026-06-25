<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * mktime() for compiled JIT/AOT modules (#9132, php-in-PHP).
 *
 * SSOT: {@see VmDate::mktime()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(mktime)
 */
final class MktimeJitHelper
{
    public const TAG_FALSE = 0;

    public const TAG_INT = 1;

    private static int $lastTimestamp = 0;

    public static function mktimeArgv(
        int $hour,
        int $minute,
        int $second,
        int $month,
        int $day,
        int $year,
        int $useCurrentLocal
    ): int {
        if (0 !== $useCurrentLocal) {
            $result = VmDate::mktime($hour);
        } else {
            $result = VmDate::mktime($hour, $minute, $second, $month, $day, $year);
        }
        if (false === $result) {
            return self::TAG_FALSE;
        }
        self::$lastTimestamp = $result;

        return self::TAG_INT;
    }

    public static function lastTimestamp(): int
    {
        return self::$lastTimestamp;
    }
}
