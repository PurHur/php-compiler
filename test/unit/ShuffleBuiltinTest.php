<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\shuffle_;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for shuffle(). */
final class ShuffleBuiltinTest extends TestCase
{
    public function testPreservesMultiset(): void
    {
        $runtime = new Runtime();
        $fn = new shuffle_();
        $ht = new HashTable();
        foreach ([3, 1, 2] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $shuffled = $this->runShuffle($fn, $runtime, $ht);
        $vals = [];
        foreach ($shuffled->iterate(true) as $v) {
            $vals[] = $v->toInt();
        }
        \sort($vals);
        $this->assertSame([1, 2, 3], $vals);
    }

    private function runShuffle(Internal $fn, Runtime $runtime, HashTable $array): HashTable
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
