<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\natsort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for natsort(). */
final class NatsortBuiltinTest extends TestCase
{
    public function testNaturalSortsPackedStrings(): void
    {
        $runtime = new Runtime();
        $fn = new natsort_();
        $ht = new HashTable();
        foreach (['img12', 'img10', 'img2', 'img1'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runNatsort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toString();
        }
        $this->assertSame(['img1', 'img2', 'img10', 'img12'], $vals);
    }

    public function testNaturalSortPreservesPackedListKeys(): void
    {
        $runtime = new Runtime();
        $fn = new natsort_();
        $ht = new HashTable();
        foreach (['b', 'a10', 'a2'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runNatsort($fn, $runtime, $ht);
        $out = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $out[$key->toInt()] = $value->toString();
        }
        $this->assertSame([2 => 'a2', 1 => 'a10', 0 => 'b'], $out);
    }

    public function testNaturalSortsPackedIntegersWithSharedRefcount(): void
    {
        $runtime = new Runtime();
        $fn = new natsort_();
        $ht = new HashTable();
        foreach ([3, 1, 2] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $ht->addRef();
        $sorted = $this->runNatsort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toInt();
        }
        $this->assertSame([1, 2, 3], $vals);
    }

    public function testNaturalSortsStringKeysByValue(): void
    {
        $runtime = new Runtime();
        $fn = new natsort_();
        $ht = new HashTable();
        foreach (['b' => 'v10', 'a' => 'v2', 'c' => 'v1'] as $k => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->add($k, $val);
        }
        $sorted = $this->runNatsort($fn, $runtime, $ht);
        $out = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $out[] = $key->toString().':'.$value->toString();
        }
        $this->assertSame(['c:v1', 'a:v2', 'b:v10'], $out);
    }

    private function runNatsort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($array);
        $frame->calledArgs = [$arrayVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $arrayVar->resolveIndirect()->toArray();
    }
}
