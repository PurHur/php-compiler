<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_change_key_case() for compiled JIT/AOT modules (#12371, php-in-PHP).
 *
 * SSOT: {@see VmArray::changeKeyCase()}
 * php-src: ext/standard/array.c — php_array_change_key_case()
 */
final class ArrayChangeKeyCaseJitHelper
{
    public static function changeKeyCase(HashTable $ht, int $case): HashTable
    {
        return VmArray::changeKeyCase($ht, $case);
    }
}
