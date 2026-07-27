<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_slice() for compiled JIT/AOT modules (#12410, php-in-PHP).
 *
 * NestedJIT-safe reimplementation of {@see HashTable::sliceCopy()} — do not call
 * `$ht->sliceCopy()` from this helper: NestedJIT would recurse through
 * ArraySliceRuntime → this method (#23974). Uses exportKeyValuePairs / iterate /
 * isPackedList / append which NestedJIT already lowers (#12908 / #14601).
 *
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
        $lengthOrNull = $hasLength ? $length : null;
        if ($preserveKeys) {
            return self::slicePreserveKeys($ht, $offset, $lengthOrNull);
        }
        if ($ht->isPackedList()) {
            return self::slicePacked($ht, $offset, $lengthOrNull);
        }

        return self::sliceReindexIntKeys($ht, $offset, $lengthOrNull);
    }

    private static function slicePacked(HashTable $ht, int $offset, ?int $length): HashTable
    {
        [$offset, $takeLen] = self::normalizeSpliceRange($offset, $length, $ht->getNumElements());
        $out = new HashTable();
        $i = 0;
        foreach ($ht->iterate(true) as $value) {
            if ($i >= $offset && $i < $offset + $takeLen) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $out->append($copy);
            }
            ++$i;
        }

        return $out;
    }

    private static function sliceReindexIntKeys(HashTable $ht, int $offset, ?int $length): HashTable
    {
        [$offset, $takeLen] = self::normalizeSpliceRange($offset, $length, $ht->getNumElements());
        $out = new HashTable();
        $nextIntKey = 0;
        $i = 0;
        // foreach — NestedJIT does not lower list index on exportKeyValuePairs (#23974).
        foreach ($ht->exportKeyValuePairs(true) as [$key, $value]) {
            if ($i >= $offset && $i < $offset + $takeLen) {
                $copy = new Variable();
                $copy->copyFrom($value);
                if (Variable::TYPE_STRING === $key->type) {
                    $out->add($key->toString(), $copy);
                } else {
                    $out->addIndex($nextIntKey, $copy);
                    ++$nextIntKey;
                }
            }
            ++$i;
        }

        return $out;
    }

    private static function slicePreserveKeys(HashTable $ht, int $offset, ?int $length): HashTable
    {
        [$offset, $takeLen] = self::normalizeSpliceRange($offset, $length, $ht->getNumElements());
        $out = new HashTable();
        $i = 0;
        foreach ($ht->exportKeyValuePairs(true) as [$key, $value]) {
            if ($i >= $offset && $i < $offset + $takeLen) {
                $copy = new Variable();
                $copy->copyFrom($value);
                if (Variable::TYPE_STRING === $key->type) {
                    $out->add($key->toString(), $copy);
                } else {
                    $out->addIndex($key->toInt(), $copy);
                }
            }
            ++$i;
        }

        return $out;
    }

    /** @return array{0: int, 1: int} */
    private static function normalizeSpliceRange(int $offset, ?int $length, int $num): array
    {
        if ($offset < 0) {
            $offset = $num + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if (null === $length) {
            $removeLen = $num - $offset;
        } elseif ($length < 0) {
            $removeLen = $num - $offset + $length;
        } else {
            $removeLen = $length;
        }
        if ($removeLen < 0) {
            $removeLen = 0;
        }
        if ($offset >= $num) {
            $removeLen = 0;
        } elseif ($removeLen > $num - $offset) {
            $removeLen = $num - $offset;
        }

        return [$offset, $removeLen];
    }
}
