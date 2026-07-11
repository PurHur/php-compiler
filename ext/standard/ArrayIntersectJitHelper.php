<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_intersect() for compiled JIT/AOT modules (#12529, php-in-PHP).
 *
 * SSOT: {@see VmArray::intersectSingleArgumentCopy()}, {@see VmArray::intersectTwo()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_intersect)
 */
final class ArrayIntersectJitHelper
{
    public static function intersectSingleCopy(HashTable $first): HashTable
    {
        return VmArray::intersectSingleArgumentCopy($first);
    }

    public static function intersectTwo(HashTable $first, HashTable $other): HashTable
    {
        return VmArray::intersectTwo($first, $other);
    }
}
