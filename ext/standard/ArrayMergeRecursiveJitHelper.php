<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_merge_recursive() for compiled JIT/AOT modules (#10183, php-in-PHP).
 *
 * SSOT: {@see HashTable::mergeRecursiveCopy()}
 * php-src: ext/standard/array.c — php_array_merge_recursive()
 */
final class ArrayMergeRecursiveJitHelper
{
    public static function mergeSingleCopy(HashTable $first): HashTable
    {
        return $first->mergeRecursiveCopy();
    }

    public static function mergeTwo(HashTable $first, HashTable $second): HashTable
    {
        return $first->mergeRecursiveCopy($second);
    }
}
