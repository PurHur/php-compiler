<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\natcasesort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for natcasesort(). */
final class NatcasesortBuiltinTest extends TestCase
{
    public function testNaturalCaseSortsPackedStrings(): void
    {
        $runtime = new Runtime();
        $fn = new natcasesort_();
        $ht = new HashTable();
        foreach (['Img12', 'img10', 'IMG2', 'img1'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runNatcasesort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toString();
        }
        $this->assertSame(['img1', 'IMG2', 'img10', 'Img12'], $vals);
    }

    public function testNaturalCaseSortsPackedIntegersWithSharedRefcount(): void
    {
        $runtime = new Runtime();
        $fn = new natcasesort_();
        $ht = new HashTable();
        foreach ([3, 1, 2] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $ht->addRef();
        $sorted = $this->runNatcasesort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toInt();
        }
        $this->assertSame([1, 2, 3], $vals);
    }

    public function testNaturalCaseSortsStringKeysByValue(): void
    {
        $runtime = new Runtime();
        $fn = new natcasesort_();
        $ht = new HashTable();
        foreach (['b' => 'V10', 'a' => 'v2', 'c' => 'V1'] as $k => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->add($k, $val);
        }
        $sorted = $this->runNatcasesort($fn, $runtime, $ht);
        $out = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $out[] = $key->toString().':'.$value->toString();
        }
        $this->assertSame(['c:V1', 'a:v2', 'b:V10'], $out);
    }

    public function testNaturalCaseSortWithNullKeepsStringNaturalOrder(): void
    {
        $runtime = new Runtime();
        $fn = new natcasesort_();
        $ht = new HashTable();
        $null = new VMVariable();
        $null->null();
        $ht->addIndex(0, $null);
        foreach ([1 => 'img2.png', 2 => 'img10.png', 3 => 'img1.png'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runNatcasesort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = VMVariable::TYPE_NULL === $v->type ? null : $v->toString();
        }
        $this->assertSame([null, 'img1.png', 'img2.png', 'img10.png'], $vals);
    }

    private function runNatcasesort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
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
