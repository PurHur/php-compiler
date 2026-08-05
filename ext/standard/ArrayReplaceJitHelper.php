<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_replace() for compiled JIT/AOT modules (#12516, #27519, php-in-PHP).
 *
 * NestedJIT lowers {@see HashTable::replaceCopy()} via
 * {@see \PHPCompiler\JIT\Call\HashTableReplaceCopy} / {@see \PHPCompiler\JIT\HashTableCowLlvm}.
 * SSOT: {@see HashTable::replaceCopy()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace)
 */
final class ArrayReplaceJitHelper
{
    public static function replaceSingleCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    public static function replaceTwo(HashTable $base, HashTable $overlay): HashTable
    {
        return $base->replaceCopy($overlay);
    }
}
