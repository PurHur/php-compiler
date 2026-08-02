<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_replace_recursive() for compiled JIT/AOT modules (#12638, #26977, php-in-PHP).
 *
 * NestedJIT lowers {@see HashTable::replaceRecursiveCopy()} via
 * {@see \PHPCompiler\JIT\Call\HashTableReplaceRecursiveCopy} / {@see \PHPCompiler\JIT\HashTableReplaceRecursiveLlvm}.
 * VM SSOT: {@see HashTable::replaceRecursiveCopy()}
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
