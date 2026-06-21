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
        $repl = new HashTable();
        $repl->append($nine);
        $repl->append($ten);
        $removed = $ht->spliceInPlace(1, 2, $repl);

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
        $removed = $ht->spliceInPlace(-2, null);

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
        $repl = new HashTable();
        $repl->append($five);
        $repl->append($six);
        $removed = $ht->spliceInPlace(1, 0, $repl);

        $this->assertSame(0, $removed->getNumElements());
        $this->assertSame(5, $ht->getNumElements());
        $this->assertSame(0, $ht->findIndex(0)->toInt());
        $this->assertSame(5, $ht->findIndex(1)->toInt());
        $this->assertSame(6, $ht->findIndex(2)->toInt());
        $this->assertSame(1, $ht->findIndex(3)->toInt());
        $this->assertSame(2, $ht->findIndex(4)->toInt());
    }

    public function testSpliceAssociativePreservesKeys(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $value) {
            $var = new Variable();
            $var->int($value);
            $ht->add($key, $var);
        }
        $removed = $ht->spliceInPlace(1, 1);

        $this->assertSame(2, $ht->getNumElements());
        $this->assertSame(1, $removed->getNumElements());
        $this->assertSame(1, $ht->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(3, $ht->find('c')?->resolveIndirect()->toInt());
        $this->assertSame(2, $removed->find('b')?->resolveIndirect()->toInt());
    }

    public function testSpliceAssociativeNegativeOffset(): void
    {
        $ht = new HashTable();
        foreach (['x' => 10, 'y' => 20, 'z' => 30] as $key => $value) {
            $var = new Variable();
            $var->int($value);
            $ht->add($key, $var);
        }
        $removed = $ht->spliceInPlace(-1, null);

        $this->assertSame(2, $ht->getNumElements());
        $this->assertSame(1, $removed->getNumElements());
        $this->assertSame(30, $removed->find('z')?->resolveIndirect()->toInt());
        $this->assertNull($ht->find('z'));
    }

    public function testSliceCopyNegativeLength(): void
    {
        $ht = $this->packedList([10, 20, 30, 40, 50]);
        $slice = $ht->sliceCopy(1, -2);

        $this->assertSame(2, $slice->getNumElements());
        $this->assertSame(20, $slice->findIndex(0)?->toInt());
        $this->assertSame(30, $slice->findIndex(1)?->toInt());
    }

    public function testSliceCopyPreserveKeysNegativeOffset(): void
    {
        $ht = new HashTable();
        foreach (['a', 'b', 'c', 'd'] as $i => $value) {
            $var = new Variable();
            $var->string($value);
            $ht->addIndex($i, $var);
        }
        $slice = $ht->sliceCopy(-2, 2, true);

        $this->assertSame(2, $slice->getNumElements());
        $this->assertSame('c', $slice->findIndex(2)?->toString());
        $this->assertSame('d', $slice->findIndex(3)?->toString());
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
