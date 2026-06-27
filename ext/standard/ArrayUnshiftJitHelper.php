<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_unshift() for compiled JIT/AOT modules (#12717, php-in-PHP).
 *
 * SSOT shared with {@see array_unshift} VM execute()
 * php-src: ext/standard/array.c — php_array_unshift()
 */
final class ArrayUnshiftJitHelper
{
    public static function countElements(HashTable $ht): int
    {
        return $ht->getNumElements();
    }

    public static function unshiftFromList(HashTable $ht, HashTable $valuesList): int
    {
        $values = [];
        foreach ($valuesList->iterate() as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }

        return $ht->unshiftPrepend(...$values);
    }

    /** @param Variable ...$values */
    public static function unshift(HashTable $ht, Variable ...$values): int
    {
        $copies = [];
        foreach ($values as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $copies[] = $copy;
        }

        return $ht->unshiftPrepend(...$copies);
    }
}
