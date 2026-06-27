<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_intersect_assoc() for compiled JIT/AOT modules (#12636, php-in-PHP).
 *
 * SSOT: {@see VmArray::intersectAssocSingleArgumentCopy()}, {@see VmArray::intersectAssocTwo()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_intersect_assoc)
 */
final class ArrayIntersectAssocJitHelper
{
    public static function intersectAssocSingleCopy(HashTable $first): HashTable
    {
        return VmArray::intersectAssocSingleArgumentCopy($first);
    }

    public static function intersectAssocTwo(HashTable $first, HashTable $other): HashTable
    {
        return VmArray::intersectAssocTwo($first, $other);
    }
}
