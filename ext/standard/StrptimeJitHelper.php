<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * strptime() for compiled JIT/AOT modules (#9132, php-in-PHP).
 *
 * SSOT: {@see VmDate::strptime()}
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(strptime)
 */
final class StrptimeJitHelper
{
    public static function strptimeArgv(string $date, string $format): HashTable|false
    {
        return VmDate::strptime($date, $format);
    }
}
