<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_combine() for compiled JIT/AOT modules (#12502, php-in-PHP).
 *
 * SSOT: {@see VmArray::combine()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_combine)
 */
final class ArrayCombineJitHelper
{
    public static function combineCopy(HashTable $keys, HashTable $values): HashTable
    {
        return VmArray::combine($keys, $values, null);
    }
}
