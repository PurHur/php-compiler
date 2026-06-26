<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_reverse() for compiled JIT/AOT modules (#12329, php-in-PHP).
 *
 * SSOT: {@see HashTable::reverseCopy()}
 * php-src: ext/standard/array.c — php_array_reverse()
 */
final class ArrayReverseJitHelper
{
    public static function reverseCopy(HashTable $ht, bool $preserveKeys): HashTable
    {
        return $ht->reverseCopy($preserveKeys);
    }
}
