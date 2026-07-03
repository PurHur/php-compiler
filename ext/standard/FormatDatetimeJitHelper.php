<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * date()/gmdate() for compiled JIT/AOT modules (#15243, php-in-PHP).
 *
 * SSOT: {@see VmDate::date()} / {@see VmDate::gmdate()}
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date), PHP_FUNCTION(gmdate)
 */
final class FormatDatetimeJitHelper
{
    public static function formatDatetimeArgv(string $format, int $timestamp, int $gmt): string
    {
        if (0 !== $gmt) {
            return VmDate::gmdate($format, $timestamp);
        }

        return VmDate::date($format, $timestamp);
    }
}
