<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_slice() for compiled JIT/AOT modules (#12410, php-in-PHP).
 *
 * SSOT: {@see HashTable::sliceCopy()}
 * php-src: ext/standard/array.c — php_array_slice()
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
        return $ht->sliceCopy($offset, $hasLength ? $length : null, $preserveKeys);
    }
}
