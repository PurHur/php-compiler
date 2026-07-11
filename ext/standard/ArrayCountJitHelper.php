<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * count($array) COUNT_NORMAL for compiled JIT/AOT modules (#13276, php-in-PHP).
 *
 * SSOT: {@see HashTable::getNumElements()}
 * php-src: ext/standard/array.c — php_count
 */
final class ArrayCountJitHelper
{
    public static function numElements(HashTable $ht): int
    {
        return $ht->getNumElements();
    }
}
