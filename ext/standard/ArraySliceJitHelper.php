<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_slice() for compiled JIT/AOT modules (#12410, php-in-PHP).
 *
 * SSOT: {@see HashTable::sliceCopy()}
 * php-src: ext/standard/array.c — php_array_slice()
 *
 * NestedJIT lowers `$ht->sliceCopy()` via {@see \PHPCompiler\JIT\Call\HashTableSliceCopy}
 * (pure LLVM — must not re-enter ArraySliceRuntime, #23974).
 *
 * Do not pass PHP `null` for omitted length: NestedJIT drops null call args so the
 * following `$preserveKeys` is read as `$length` (empty slices). Pass an explicit
 * upper bound (`getNumElements()`) instead; {@see HashTable::sliceCopy()} clamps.
 */
final class ArraySliceJitHelper
{
    public static function sliceCopy(
        HashTable $ht,
        int $offset,
        bool $hasLength,
        int $length,
        bool $preserveKeys
    ): HashTable {
        if ($hasLength) {
            return $ht->sliceCopy($offset, $length, $preserveKeys);
        }

        return $ht->sliceCopy($offset, $ht->getNumElements(), $preserveKeys);
    }
}
