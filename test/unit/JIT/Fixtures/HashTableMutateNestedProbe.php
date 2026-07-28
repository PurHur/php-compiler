<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT\Fixtures;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Minimal NestedJIT probe for HashTable in-place mutators (#24157).
 *
 * Not a production helper — only NestedJIT-compiled by HashTableMutateNestedJitTest.
 */
final class HashTableMutateNestedProbe
{
    /**
     * @param list<Variable> $values
     */
    public static function replacePacked(HashTable $ht, array $values): void
    {
        $ht->replacePackedValues($values);
    }

    /**
     * @param list<Variable> $values
     */
    public static function assignPacked(HashTable $ht, array $values): void
    {
        $ht->assignPackedList($values);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function reorderKeyed(HashTable $ht, array $pairs): void
    {
        $ht->reorderKeyedPairs($pairs);
    }
}
