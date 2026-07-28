<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_shift() for the interpreted VM (#12672, php-in-PHP).
 *
 * JIT/AOT uses {@see \PHPCompiler\JIT\Builtin\ArrayShiftRuntime} → {@see \PHPCompiler\JIT\HashTableShiftLlvm}
 * (NestedJIT of this helper segfaults on Variable returns under thin standalone AOT — #24025).
 *
 * SSOT shared with {@see array_shift} VM execute()
 * php-src: ext/standard/array.c — php_array_shift()
 */
final class ArrayShiftJitHelper
{
    public static function shift(HashTable $ht): Variable
    {
        return $ht->shiftFirst();
    }
}
