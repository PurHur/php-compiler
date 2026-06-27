<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_diff_assoc() for compiled JIT/AOT modules (#12552, php-in-PHP).
 *
 * SSOT: {@see VmArray::diffAssocSingleArgumentCopy()}, {@see VmArray::diffAssocTwo()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_diff_assoc)
 */
final class ArrayDiffAssocJitHelper
{
    public static function diffAssocSingleCopy(HashTable $first): HashTable
    {
        return VmArray::diffAssocSingleArgumentCopy($first);
    }

    public static function diffAssocTwo(HashTable $first, HashTable $other): HashTable
    {
        return VmArray::diffAssocTwo($first, $other);
    }
}
