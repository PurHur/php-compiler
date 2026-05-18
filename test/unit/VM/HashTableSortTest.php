<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPUnit\Framework\TestCase;

final class HashTableSortTest extends TestCase
{
    public function testReplacePackedValuesSortsStrings(): void
    {
        $ht = new HashTable();
        foreach (['b', 'a', 'c'] as $s) {
            $var = new Variable();
            $var->string($s);
            $ht->append($var);
        }
        $sorted = ['a', 'b', 'c'];
        $values = [];
        foreach ($sorted as $s) {
            $var = new Variable();
            $var->string($s);
            $values[] = $var;
        }
        $ht->replacePackedValues($values);
        $this->assertSame('a', $ht->findIndex(0)->toString());
        $this->assertSame('b', $ht->findIndex(1)->toString());
        $this->assertSame('c', $ht->findIndex(2)->toString());
    }
}
