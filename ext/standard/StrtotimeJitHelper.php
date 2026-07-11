<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * strtotime() for compiled JIT/AOT modules (#10742, php-in-PHP).
 *
 * SSOT: {@see VmDateTimeNative::strtotime()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime)
 */
final class StrtotimeJitHelper
{
    public const TAG_FALSE = 0;

    public const TAG_INT = 1;

    private static int $lastTimestamp = 0;

    public static function strtotimeArgv(string $time, int $hasBase, int $baseTimestamp): int
    {
        $result = 0 !== $hasBase
            ? VmDateTimeNative::strtotime($time, $baseTimestamp)
            : VmDateTimeNative::strtotime($time);
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
