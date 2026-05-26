<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\rsort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for rsort(). */
final class RsortBuiltinTest extends TestCase
{
    public function testSortsPackedStringsDescending(): void
    {
        $runtime = new Runtime();
        $fn = new rsort_();
        $ht = new HashTable();
        foreach (['b', 'a', 'c'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runRsort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toString();
        }
        $this->assertSame(['c', 'b', 'a'], $vals);
    }

    public function testSortsPackedIntegersDescending(): void
    {
        $runtime = new Runtime();
        $fn = new rsort_();
        $ht = new HashTable();
        foreach ([3, 1, 2] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runRsort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toInt();
        }
        $this->assertSame([3, 2, 1], $vals);
    }

    private function runRsort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
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
