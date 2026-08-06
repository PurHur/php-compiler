<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Flatten nested hashtables to LEAVES_ONLY order for thin-AOT RecursiveIteratorIterator (#26775, #27257).
 *
 * Param is untyped so NestedJIT does not emit a HashTable param guard that rejects the
 * ABI `__hashtable__*` bridge coercion (peer HashTableJitHelper NestedJIT hazards #23548).
 *
 * Returns [valuesHt, keysHt] — packed leaf values plus original leaf keys (Zend ITA overwrite).
 *
 * php-src: ext/spl/spl_iterators.c — RecursiveIteratorIterator default LEAVES_ONLY.
 */
final class RecursiveLeavesFlattenJitHelper
{
    /**
     * @param HashTable $src
     *
     * @return array{0: HashTable, 1: HashTable}
     */
    public static function flattenLeavesWithKeys($src): array
    {
        if (!$src instanceof HashTable) {
            throw new \TypeError(
                'RecursiveLeavesFlattenJitHelper::flattenLeavesWithKeys(): Argument #1 ($src) must be of type '
                .HashTable::class.', '.\get_debug_type($src).' given'
            );
        }
        $out = new HashTable();
        $keys = new HashTable();
        self::walk($src, $out, $keys);

        return [$out, $keys];
    }

    /** @param HashTable $src */
    public static function flattenLeaves($src)
    {
        return self::flattenLeavesWithKeys($src)[0];
    }

    private static function walk(HashTable $src, HashTable $out, HashTable $keys): void
    {
        $src->iterReset();
        while ($src->iterValid()) {
            $value = $src->iterCurrentValue()->resolveIndirect();
            if (Variable::TYPE_ARRAY === $value->type) {
                self::walk($value->toArray(), $out, $keys);
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $out->append($copy);
            $keyCopy = new Variable();
            $keyCopy->copyFrom($src->iterCurrentKey());
            $keys->append($keyCopy);
        }
    }
}
