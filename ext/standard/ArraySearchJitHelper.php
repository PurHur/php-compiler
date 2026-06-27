<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_search() for compiled JIT/AOT modules (#12514, php-in-PHP).
 *
 * SSOT: {@see VmArray::searchKey()}
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_search)
 */
final class ArraySearchJitHelper
{
    public static function searchKey(Variable $needle, HashTable $haystack, bool $strict): Variable
    {
        return VmArray::searchKey($needle, $haystack, $strict);
    }
}
