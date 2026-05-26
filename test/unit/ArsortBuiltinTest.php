<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\arsort_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for arsort(). */
final class ArsortBuiltinTest extends TestCase
{
    public function testSortsStringKeysByValueDescending(): void
    {
        $runtime = new Runtime();
        $fn = new arsort_();
        $ht = new HashTable();
        foreach (['b' => 2, 'a' => 1, 'c' => 3] as $k => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->add($k, $val);
        }
        $sorted = $this->runArsort($fn, $runtime, $ht);
        $out = [];
        foreach ($sorted->iterateKeyed(true) as [$key, $value]) {
            $out[] = $key->toString().':'.$value->toInt();
        }
        $this->assertSame(['c:3', 'b:2', 'a:1'], $out);
    }

    public function testSortsPackedListValuesDescending(): void
    {
        $runtime = new Runtime();
        $fn = new arsort_();
        $ht = new HashTable();
        foreach ([3, 1, 2] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $sorted = $this->runArsort($fn, $runtime, $ht);
        $vals = [];
        foreach ($sorted->iterate(true) as $v) {
            $vals[] = $v->toInt();
        }
        $this->assertSame([3, 2, 1], $vals);
    }

    private function runArsort(Internal $fn, Runtime $runtime, HashTable $array): HashTable
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
