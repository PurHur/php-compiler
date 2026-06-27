<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_chunk() for compiled JIT/AOT modules (#12455, php-in-PHP).
 *
 * SSOT: {@see HashTable::chunkCopy()}
 * php-src: ext/standard/array.c — php_array_chunk()
 */
final class ArrayChunkJitHelper
{
    public static function chunkCopy(HashTable $ht, int $size, bool $preserveKeys): HashTable
    {
        return $ht->chunkCopy($size, $preserveKeys);
    }
}
