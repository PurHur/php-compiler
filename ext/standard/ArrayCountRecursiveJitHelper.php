<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * count($array, COUNT_RECURSIVE) for compiled JIT/AOT modules (#13274, php-in-PHP).
 *
 * SSOT: {@see VmArray::countRecursiveForCompiled()}
 * php-src: ext/standard/array.c — php_count_recursive
 */
final class ArrayCountRecursiveJitHelper
{
    public static function countRecursive(HashTable $ht): int
    {
        return VmArray::countRecursiveForCompiled($ht);
    }
}
