<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * array_values() for compiled JIT/AOT modules (#12329, php-in-PHP).
 *
 * SSOT: {@see HashTable::valuesCopy()}
 * php-src: ext/standard/array.c — php_array_values()
 */
final class ArrayValuesJitHelper
{
    public static function valuesCopy(HashTable $ht): HashTable
    {
        return $ht->valuesCopy();
    }
}
