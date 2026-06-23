<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\krsort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for krsort(). */
final class KrsortBuiltinTest extends TestCase
{
    public function testSortsStringKeysDescending(): void
    {
        $runtime = new Runtime();
        $fn = new krsort_();
        $ht = new HashTable();
        foreach (['b' => 2, 'a' => 1, 'c' => 3] as $k => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->add($k, $val);
        }
        $sorted = $this->runKrsort($fn, $runtime, $ht);
        $keys = [];
        foreach ($sorted->iterateKeyed(true) as [$key]) {
            $keys[] = $key->toString();
        }
        $this->assertSame(['c', 'b', 'a'], $keys);
    }

    public function testSortsIntegerKeysDescending(): void
    {
        $runtime = new Runtime();
        $fn = new krsort_();
        $ht = new HashTable();
        foreach ([30 => 'c', 10 => 'a', 20 => 'b'] as $k => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($k, $val);
        }
        $sorted = $this->runKrsort($fn, $runtime, $ht);
        $keys = [];
        foreach ($sorted->iterateKeyed(true) as [$key]) {
            $keys[] = $key->toInt();
        }
        $this->assertSame([30, 20, 10], $keys);
    }

    public function testPackedListSortsByKeyDescending(): void
    {
        $runtime = new Runtime();
        $fn = new krsort_();
        $ht = new HashTable();
        foreach ([1, 2, 3] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runKrsort($fn, $runtime, $ht);
        $keys = [];
        foreach ($sorted->iterateKeyed(true) as [$key]) {
            $keys[] = $key->toInt();
        }
        $this->assertSame([2, 1, 0], $keys);
    }

    private function runKrsort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
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
