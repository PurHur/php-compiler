<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * idate() for compiled JIT/AOT modules (#9181 slice, php-in-PHP).
 *
 * Returns int part value, or ERR_FORMAT / ERR_TOKEN sentinels for the LLVM bridge.
 * SSOT: {@see VmDate::idateValue()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(idate)
 */
final class IdateJitHelper
{
    private const ERR_FORMAT = -1;

    private const ERR_TOKEN = -2;

    private const MSG_FORMAT_ONE_CHAR = 'idate format is one char';

    private const MSG_UNRECOGNIZED = 'Unrecognized date format token';

    public static function idate(string $format, int $timestamp): int
    {
        if (1 !== \strlen($format)) {
            trigger_error(self::MSG_FORMAT_ONE_CHAR, \E_USER_WARNING);

            return self::ERR_FORMAT;
        }
        $value = VmDate::idateValue($format, $timestamp);
        if (false === $value) {
            trigger_error(self::MSG_UNRECOGNIZED, \E_USER_WARNING);

            return self::ERR_TOKEN;
        }

        return $value;
    }
}
