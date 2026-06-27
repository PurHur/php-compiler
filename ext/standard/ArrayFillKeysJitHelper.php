<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_fill_keys() for compiled JIT/AOT modules (#12487, php-in-PHP).
 *
 * SSOT: {@see VmArray::fillKeys()}
 * php-src: ext/standard/array.c — php_array_fill_keys()
 */
final class ArrayFillKeysJitHelper
{
    public static function fillKeysCopy(HashTable $keys, Variable $value): HashTable
    {
        return VmArray::fillKeys($keys, $value, null);
    }
}
