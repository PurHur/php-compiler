<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_diff_key() for compiled JIT/AOT modules (#12553, php-in-PHP).
 *
 * SSOT: {@see VmArray::diffKeySingleArgumentCopy()}, {@see VmArray::diffKeyTwo()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_diff_key)
 */
final class ArrayDiffKeyJitHelper
{
    public static function diffKeySingleCopy(HashTable $first): HashTable
    {
        return VmArray::diffKeySingleArgumentCopy($first);
    }

    public static function diffKeyTwo(HashTable $first, HashTable $other): HashTable
    {
        return VmArray::diffKeyTwo($first, $other);
    }
}
