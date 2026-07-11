<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_diff() for compiled JIT/AOT modules (#12527, php-in-PHP).
 *
 * SSOT: {@see VmArray::diffSingleArgumentCopy()}, {@see VmArray::diffTwo()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_diff)
 */
final class ArrayDiffJitHelper
{
    public static function diffSingleCopy(HashTable $first): HashTable
    {
        return VmArray::diffSingleArgumentCopy($first);
    }

    public static function diffTwo(HashTable $first, HashTable $other): HashTable
    {
        return VmArray::diffTwo($first, $other);
    }
}
