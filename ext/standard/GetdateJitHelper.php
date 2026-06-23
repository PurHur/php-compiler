<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * getdate() for compiled JIT/AOT modules (#9181 slice, php-in-PHP).
 *
 * SSOT: {@see VmDate::getdate()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(getdate)
 */
final class GetdateJitHelper
{
    public static function getdate(int $timestamp): HashTable
    {
        return VmDate::getdate($timestamp);
    }
}
