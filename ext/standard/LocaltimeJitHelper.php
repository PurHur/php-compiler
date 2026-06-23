<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * localtime() for compiled JIT/AOT modules (#9181 slice, php-in-PHP).
 *
 * SSOT: {@see VmDate::localtimeBreakdown()}
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(localtime)
 */
final class LocaltimeJitHelper
{
    public static function localtime(int $timestamp, bool $associative): HashTable
    {
        return VmDate::localtimeBreakdown($timestamp, $associative);
    }
}
