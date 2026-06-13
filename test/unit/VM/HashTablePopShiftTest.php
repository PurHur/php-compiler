<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPUnit\Framework\TestCase;

class HashTablePopShiftTest extends TestCase
{
    public function testPopLast(): void
    {
        $ht = new HashTable();
        foreach ([1, 2, 3] as $n) {
            $var = new Variable();
            $var->int($n);
            $ht->append($var);
        }
        $popped = $ht->popLast();
        $this->assertNotNull($popped);
        $this->assertSame(3, $popped->toInt());
        $this->assertSame(2, $ht->getNumElements());
        $this->assertNotNull($ht->findIndex(1));
    }

    public function testShiftFirst(): void
    {
        $ht = new HashTable();
        foreach ([10, 20] as $n) {
            $var = new Variable();
            $var->int($n);
            $ht->append($var);
        }
        $shifted = $ht->shiftFirst();
        $this->assertNotNull($shifted);
        $this->assertSame(10, $shifted->toInt());
        $this->assertSame(1, $ht->getNumElements());
        $this->assertSame(20, $ht->findIndex(0)->toInt());
    }

    public function testShiftFirstAssociative(): void
    {
        $ht = new HashTable();
        foreach (['x' => 1, 'y' => 2] as $key => $n) {
            $var = new Variable();
            $var->int($n);
            $ht->add($key, $var);
        }
        $shifted = $ht->shiftFirst();
        $this->assertNotNull($shifted);
        $this->assertSame(1, $shifted->toInt());
        $this->assertSame(1, $ht->getNumElements());
        $found = $ht->find('y');
        $this->assertNotNull($found);
        $this->assertSame(2, $found->toInt());
    }

    public function testUnshiftPrependAssociative(): void
    {
        $ht = new HashTable();
        $var = new Variable();
        $var->int(1);
        $ht->add('x', $var);
        $prepend = new Variable();
        $prepend->string('z');
        $count = $ht->unshiftPrepend($prepend);
        $this->assertSame(2, $count);
        $this->assertSame('z', $ht->findIndex(0)->toString());
        $found = $ht->find('x');
        $this->assertNotNull($found);
        $this->assertSame(1, $found->toInt());
    }

    public function testPopLastAssociative(): void
    {
        $ht = new HashTable();
        foreach (['x' => 1, 'y' => 2] as $key => $n) {
            $var = new Variable();
            $var->int($n);
            $ht->add($key, $var);
        }
        $popped = $ht->popLast();
        $this->assertNotNull($popped);
        $this->assertSame(2, $popped->toInt());
        $this->assertSame(1, $ht->getNumElements());
        $found = $ht->find('x');
        $this->assertNotNull($found);
        $this->assertSame(1, $found->toInt());
    }

    public function testValuesCopy(): void
    {
        $ht = new HashTable();
        foreach (['a', 'b'] as $s) {
            $var = new Variable();
            $var->string($s);
            $ht->append($var);
        }
        $copy = $ht->valuesCopy();
        $this->assertSame(2, $copy->getNumElements());
        $this->assertSame('a', $copy->findIndex(0)->toString());
        $this->assertSame('b', $copy->findIndex(1)->toString());
    }
}
