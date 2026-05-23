<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPUnit\Framework\TestCase;

final class HashTableSpliceTest extends TestCase
{
    public function testSpliceReplaceMiddle(): void
    {
        $ht = $this->packedList([0, 1, 2, 3, 4]);
        $nine = new Variable();
        $nine->int(9);
        $ten = new Variable();
        $ten->int(10);
        $removed = $ht->spliceInPlace(1, 2, [$nine, $ten]);

        $this->assertSame(2, $removed->getNumElements());
        $this->assertSame(1, $removed->findIndex(0)->toInt());
        $this->assertSame(2, $removed->findIndex(1)->toInt());
        $this->assertSame(5, $ht->getNumElements());
        $this->assertSame(0, $ht->findIndex(0)->toInt());
        $this->assertSame(9, $ht->findIndex(1)->toInt());
        $this->assertSame(10, $ht->findIndex(2)->toInt());
        $this->assertSame(3, $ht->findIndex(3)->toInt());
        $this->assertSame(4, $ht->findIndex(4)->toInt());
    }

    public function testSpliceNegativeOffsetRemovesTail(): void
    {
        $ht = $this->packedList([0, 1, 2, 3, 4]);
        $removed = $ht->spliceInPlace(-2, null, []);

        $this->assertSame(2, $removed->getNumElements());
        $this->assertSame(3, $removed->findIndex(0)->toInt());
        $this->assertSame(4, $removed->findIndex(1)->toInt());
        $this->assertSame(3, $ht->getNumElements());
        $this->assertSame(0, $ht->findIndex(0)->toInt());
        $this->assertSame(1, $ht->findIndex(1)->toInt());
        $this->assertSame(2, $ht->findIndex(2)->toInt());
    }

    public function testSpliceInsertWithoutRemoving(): void
    {
        $ht = $this->packedList([0, 1, 2]);
        $five = new Variable();
        $five->int(5);
        $six = new Variable();
        $six->int(6);
        $removed = $ht->spliceInPlace(1, 0, [$five, $six]);

        $this->assertSame(0, $removed->getNumElements());
        $this->assertSame(5, $ht->getNumElements());
        $this->assertSame(0, $ht->findIndex(0)->toInt());
        $this->assertSame(5, $ht->findIndex(1)->toInt());
        $this->assertSame(6, $ht->findIndex(2)->toInt());
        $this->assertSame(1, $ht->findIndex(3)->toInt());
        $this->assertSame(2, $ht->findIndex(4)->toInt());
    }

    /** @param list<int> $values */
    private function packedList(array $values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }
}
