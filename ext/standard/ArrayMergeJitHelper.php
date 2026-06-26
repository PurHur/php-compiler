<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_merge() for compiled JIT/AOT modules (#10183, php-in-PHP).
 *
 * SSOT: {@see VmArray::merge()}, {@see VmArray::mergeSingleArgumentCopy()}
 * php-src: ext/standard/array.c — php_array_merge()
 */
final class ArrayMergeJitHelper
{
    public static function mergeSingleCopy(HashTable $first): HashTable
    {
        return VmArray::mergeSingleArgumentCopy($first);
    }

    public static function mergeTwo(HashTable $first, HashTable $second): HashTable
    {
        return VmArray::merge($first, $second);
    }
}
