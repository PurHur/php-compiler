<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_keys() for compiled JIT/AOT modules (#12340, php-in-PHP).
 *
 * SSOT: {@see HashTable::keysCopy()}, {@see HashTable::keysMatchingCopy()}
 * php-src: ext/standard/array.c — php_array_keys()
 */
final class ArrayKeysJitHelper
{
    public static function keysCopy(HashTable $ht): HashTable
    {
        return $ht->keysCopy();
    }

    public static function keysMatching(HashTable $ht, Variable $searchValue, bool $strict): HashTable
    {
        return $ht->keysMatchingCopy($searchValue, $strict);
    }
}
