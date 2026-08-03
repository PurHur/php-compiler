<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_search() host/VM helper (#12514, php-in-PHP).
 *
 * JIT/AOT user-script emission uses {@see \PHPCompiler\JIT\ArraySearchLlvm} (NestedJIT of this
 * class → {@see VmArray::searchKey} was an external stub under thin AOT — #27133 / #579).
 * SSOT for VM execute() and unit tests: {@see VmArray::searchKey()}.
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_search)
 */
final class ArraySearchJitHelper
{
    public static function searchKey(Variable $needle, HashTable $haystack, bool $strict): Variable
    {
        return VmArray::searchKey($needle, $haystack, $strict);
    }
}
