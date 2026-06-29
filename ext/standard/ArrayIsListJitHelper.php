<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_is_list() for compiled JIT/AOT modules (#13645, php-in-PHP).
 *
 * SSOT: {@see VmArray::isList()}
 * php-src: ext/standard/array.c — php_array_is_list()
 */
final class ArrayIsListJitHelper
{
    public static function isList(HashTable $ht): bool
    {
        return VmArray::isList($ht);
    }
}
