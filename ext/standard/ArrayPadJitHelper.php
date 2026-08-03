<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_pad() for VM / NestedJIT helpers (#12476, php-in-PHP).
 *
 * Thin AOT/JIT call sites use {@see \PHPCompiler\JIT\HashTablePadLlvm} (#26971).
 * SSOT: {@see VmArray::pad()}
 * php-src: ext/standard/array.c — php_array_pad()
 */
final class ArrayPadJitHelper
{
    /** Legacy 3-arg array_pad() bridge — pad_type unset (php-src sign-based padding). */
    public static function padCopyLegacy(HashTable $ht, int $length, Variable $value): HashTable
    {
        return VmArray::pad($ht, $length, $value, null);
    }

    /** PHP 8.4+ 4-arg array_pad() with explicit ARRAY_PAD_* mode (#14993). */
    public static function padCopyTyped(HashTable $ht, int $length, Variable $value, int $padType): HashTable
    {
        return VmArray::pad($ht, $length, $value, $padType);
    }
}
