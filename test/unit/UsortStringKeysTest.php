<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\usort_;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** usort() on string-keyed arrays reindexes without COW refcount abort (#6801). */
final class UsortStringKeysTest extends TestCase
{
    public function testStringKeyedArrayReindexesWithSharedRefcount(): void
    {
        $runtime = new Runtime();
        $usort = new usort_();

        $ht = new HashTable();
        foreach (['x' => 'c', 'y' => 'a', 'z' => 'b'] as $key => $value) {
            $cell = new VMVariable();
            $cell->string($value);
            $ht->add($key, $cell);
        }

        $arg = new VMVariable();
        $arg->array($ht);
        $alias = new VMVariable();
        $alias->copyFrom($arg);

        $callback = new VMVariable();
        $callback->string('strcmp');

        $frame = $usort->getFrame($runtime->vmContext);
        $frame->calledArgs = [$arg, $callback];
        $frame->returnVar = new VMVariable();
        $arg->separateArrayForWrite();

        $usort->execute($frame);

        $this->assertTrue($frame->returnVar->toBool());
        $sorted = $arg->toArray();
        $this->assertSame('a', $sorted->findIndex(0)->toString());
        $this->assertSame('b', $sorted->findIndex(1)->toString());
        $this->assertSame('c', $sorted->findIndex(2)->toString());

        $aliasValues = [];
        foreach ($alias->toArray()->iterateKeyed(true) as [$key, $value]) {
            $aliasValues[$key->toString()] = $value->toString();
        }
        $this->assertSame(['x' => 'c', 'y' => 'a', 'z' => 'b'], $aliasValues);
    }

    public function testBuiltinSeparatesSharedArrayBeforeReindex(): void
    {
        $runtime = new Runtime();
        $usort = new usort_();

        $ht = new HashTable();
        foreach (['x' => 'c', 'y' => 'a', 'z' => 'b'] as $key => $value) {
            $cell = new VMVariable();
            $cell->string($value);
            $ht->add($key, $cell);
        }

        $arg = new VMVariable();
        $arg->array($ht);
        $alias = new VMVariable();
        $alias->copyFrom($arg);

        $callback = new VMVariable();
        $callback->string('strcmp');

        $frame = $usort->getFrame($runtime->vmContext);
        $frame->calledArgs = [$arg, $callback];
        $frame->returnVar = new VMVariable();

        $usort->execute($frame);

        $this->assertSame('a', $arg->toArray()->findIndex(0)->toString());
        $this->assertSame('b', $arg->toArray()->findIndex(1)->toString());
        $this->assertSame('c', $arg->toArray()->findIndex(2)->toString());
    }
}
