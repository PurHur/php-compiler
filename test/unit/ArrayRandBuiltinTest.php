<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\array_rand;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for array_rand(). */
final class ArrayRandBuiltinTest extends TestCase
{
    public function testSingleKeyInRange(): void
    {
        $runtime = new Runtime();
        $fn = new array_rand();
        $ht = new HashTable();
        foreach (['a', 'b', 'c'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $key = $this->runRand($fn, $runtime, $ht, 1);
        $this->assertSame(VMVariable::TYPE_INTEGER, $key->type);
        $this->assertGreaterThanOrEqual(0, $key->toInt());
        $this->assertLessThan(3, $key->toInt());
    }

    public function testMultipleDistinctKeys(): void
    {
        $runtime = new Runtime();
        $fn = new array_rand();
        $ht = new HashTable();
        foreach ([0, 1, 2, 3] as $i) {
            $val = new VMVariable();
            $val->int($i);
            $ht->addIndex($i, $val);
        }
        $picked = $this->runRand($fn, $runtime, $ht, 2);
        $this->assertSame(VMVariable::TYPE_ARRAY, $picked->type);
        $keys = [];
        foreach ($picked->toArray()->iterate(true) as $k) {
            $keys[] = $k->toInt();
        }
        $this->assertCount(2, $keys);
        $this->assertSame(2, \count(\array_unique($keys)));
        foreach ($keys as $k) {
            $this->assertContains($k, [0, 1, 2, 3]);
        }
    }

    private function runRand(Internal $fn, Runtime $runtime, HashTable $array, int $num): VMVariable
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($array);
        $numVar = new VMVariable();
        $numVar->int($num);
        $frame->calledArgs = [$arrayVar, $numVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->resolveIndirect();
    }
}
