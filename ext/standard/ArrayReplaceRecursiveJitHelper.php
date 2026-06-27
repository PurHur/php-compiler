<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_replace_recursive() for compiled JIT/AOT modules (#12638, php-in-PHP).
 *
 * SSOT: {@see HashTable::replaceRecursiveCopy()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_recursive)
 */
final class ArrayReplaceRecursiveJitHelper
{
    public static function replaceSingleCopy(HashTable $first): HashTable
    {
        return $first->replaceRecursiveCopy();
    }

    public static function replaceTwo(HashTable $first, HashTable $second): HashTable
    {
        return $first->replaceRecursiveCopy($second);
    }
}
