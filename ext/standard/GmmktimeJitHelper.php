<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gmmktime() for compiled JIT/AOT modules (#9132, php-in-PHP).
 *
 * SSOT: {@see VmDate::gmmktime()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(gmmktime)
 */
final class GmmktimeJitHelper
{
    public const TAG_FALSE = 0;

    public const TAG_INT = 1;

    private static int $lastTimestamp = 0;

    public static function gmmktimeArgv(
        int $hour,
        int $minute,
        int $second,
        int $month,
        int $day,
        int $year,
        int $useCurrentUtc
    ): int {
        if (0 !== $useCurrentUtc) {
            $result = VmDate::gmmktime($hour);
        } else {
            $result = VmDate::gmmktime($hour, $minute, $second, $month, $day, $year);
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
