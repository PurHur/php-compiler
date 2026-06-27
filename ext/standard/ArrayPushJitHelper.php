<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_push() for compiled JIT/AOT modules (#12719, php-in-PHP).
 *
 * SSOT shared with {@see array_push} VM execute()
 * php-src: ext/standard/array.c — php_array_push()
 */
final class ArrayPushJitHelper
{
    public static function countElements(HashTable $ht): int
    {
        return $ht->getNumElements();
    }

    public static function pushFromList(HashTable $ht, HashTable $valuesList): int
    {
        foreach ($valuesList->iterate() as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->append($copy);
        }

        return $ht->getNumElements();
    }

    /** @param Variable ...$values */
    public static function push(HashTable $ht, Variable ...$values): int
    {
        foreach ($values as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->append($copy);
        }

        return $ht->getNumElements();
    }
}
