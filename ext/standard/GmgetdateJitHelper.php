<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * gmgetdate() for compiled JIT/AOT modules (#9181 slice, php-in-PHP).
 *
 * SSOT: {@see VmDate::gmgetdate()}
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(gmgetdate)
 */
final class GmgetdateJitHelper
{
    public static function gmgetdate(int $timestamp): HashTable
    {
        return VmDate::gmgetdate($timestamp);
    }
}
