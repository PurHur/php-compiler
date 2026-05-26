<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_count_values;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_count_values() (#2356). */
final class ArrayCountValuesBuiltinTest extends TestCase
{
    public function testCountsStrings(): void
    {
        $ht = new HashTable();
        foreach (['a', 'b', 'a'] as $i => $s) {
            $v = new VMVariable();
            $v->string($s);
            $ht->addIndex($i, $v);
        }
        $arr = new VMVariable();
        $arr->array($ht);

        $runtime = new Runtime();
        $fn = new array_count_values();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$arr];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $out = $frame->returnVar->resolveIndirect()->toArray();
        $this->assertSame(2, $out->find('a')->resolveIndirect()->toInt());
        $this->assertSame(1, $out->find('b')->resolveIndirect()->toInt());
    }

    public function testCountsIntegers(): void
    {
        $ht = new HashTable();
        foreach ([1, 2, 1] as $i => $n) {
            $v = new VMVariable();
            $v->int($n);
            $ht->addIndex($i, $v);
        }
        $arr = new VMVariable();
        $arr->array($ht);

        $runtime = new Runtime();
        $fn = new array_count_values();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [$arr];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        $out = $frame->returnVar->resolveIndirect()->toArray();
        $this->assertSame(2, $out->findIndex(1)->resolveIndirect()->toInt());
        $this->assertSame(1, $out->findIndex(2)->resolveIndirect()->toInt());
    }
}
