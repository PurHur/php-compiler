<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Flatten nested hashtables to LEAVES_ONLY order for thin-AOT RecursiveIteratorIterator (#26775).
 *
 * Param is untyped so NestedJIT does not emit a HashTable param guard that rejects the
 * ABI `__hashtable__*` bridge coercion (peer HashTableJitHelper NestedJIT hazards #23548).
 *
 * php-src: ext/spl/spl_iterators.c — RecursiveIteratorIterator default LEAVES_ONLY.
 */
final class RecursiveLeavesFlattenJitHelper
{
    /** @param HashTable $src */
    public static function flattenLeaves($src)
    {
        if (!$src instanceof HashTable) {
            throw new \TypeError(
                'RecursiveLeavesFlattenJitHelper::flattenLeaves(): Argument #1 ($src) must be of type '
                .HashTable::class.', '.\get_debug_type($src).' given'
            );
        }
        $out = new HashTable();
        self::walk($src, $out);

        return $out;
    }

    private static function walk(HashTable $src, HashTable $out): void
    {
        $src->iterReset();
        while ($src->iterValid()) {
            $value = $src->iterCurrentValue()->resolveIndirect();
            if (Variable::TYPE_ARRAY === $value->type) {
                self::walk($value->toArray(), $out);
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $out->append($copy);
        }
    }
}
