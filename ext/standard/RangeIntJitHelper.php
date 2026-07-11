<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * range() int path for compiled JIT/AOT modules (#13502, php-in-PHP).
 *
 * SSOT: {@see VmRange::intRangeTable()}
 * php-src: ext/standard/array.c — php_range()
 */
final class RangeIntJitHelper
{
    public static function intRangeCopy(int $start, int $end, int $step): HashTable
    {
        return VmRange::intRangeTable($start, $end, $step);
    }
}
