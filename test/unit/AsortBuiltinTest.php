<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\asort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for asort(). */
final class AsortBuiltinTest extends TestCase
{
    public function testSortsStringKeysByValueAscending(): void
    {
        $runtime = new Runtime();
        $fn = new asort_();
        $ht = new HashTable();
        foreach (['b' => 2, 'a' => 1, 'c' => 3] as $k => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->add($k, $val);
        }
        $sorted = $this->runAsort($fn, $runtime, $ht);
        $out = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $out[] = $key->toString().':'.$value->toInt();
        }
        $this->assertSame(['a:1', 'b:2', 'c:3'], $out);
    }

    public function testSortsPackedListValues(): void
    {
        $runtime = new Runtime();
        $fn = new asort_();
        $ht = new HashTable();
        foreach ([3, 1, 2] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runAsort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toInt();
        }
        $this->assertSame([1, 2, 3], $vals);
    }

    private function runAsort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
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
