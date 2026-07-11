<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_intersect_key() for compiled JIT/AOT modules (#12551, php-in-PHP).
 *
 * SSOT: {@see VmArray::intersectKeySingleArgumentCopy()}, {@see VmArray::intersectKeyTwo()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_intersect_key)
 */
final class ArrayIntersectKeyJitHelper
{
    public static function intersectKeySingleCopy(HashTable $first): HashTable
    {
        return VmArray::intersectKeySingleArgumentCopy($first);
    }

    public static function intersectKeyTwo(HashTable $first, HashTable $other): HashTable
    {
        return VmArray::intersectKeyTwo($first, $other);
    }
}
