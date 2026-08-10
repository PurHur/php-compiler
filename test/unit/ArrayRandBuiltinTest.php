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
        foreach (['x', 'y', 'z'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $key = $this->runRand($fn, $runtime, $ht, 1);
        $this->assertGreaterThanOrEqual(0, $key);
        $this->assertLessThanOrEqual(2, $key);
    }

    public function testEmptyArrayThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new array_rand();
        $ht = new HashTable();
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('array_rand(): Argument #1 ($array) must not be empty');
        $this->runRandResult($fn, $runtime, $ht, 1);
    }

    public function testNumZeroThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new array_rand();
        $ht = new HashTable();
        $val = new VMVariable();
        $val->string('a');
        $ht->addIndex(0, $val);
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)'
        );
        $this->runRandResult($fn, $runtime, $ht, 0);
    }

    public function testNumExceedsCountThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new array_rand();
        $ht = new HashTable();
        foreach (['a', 'b'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage(
            'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)'
        );
        $this->runRandResult($fn, $runtime, $ht, 3);
    }

    public function testMultipleUniqueKeys(): void
    {
        $runtime = new Runtime();
        $fn = new array_rand();
        $ht = new HashTable();
        for ($i = 0; $i < 4; ++$i) {
            $val = new VMVariable();
            $val->int($i);
            $ht->addIndex($i, $val);
        }
        $result = $this->runRandResult($fn, $runtime, $ht, 3);
        $this->assertSame(VMVariable::TYPE_ARRAY, $result->type);
        $picked = [];
        foreach ($result->toArray()->iterate(true) as $v) {
            $picked[] = $v->toInt();
        }
        $this->assertCount(3, $picked);
        $this->assertCount(3, array_unique($picked));
        foreach ($picked as $k) {
            $this->assertGreaterThanOrEqual(0, $k);
            $this->assertLessThanOrEqual(3, $k);
        }
    }

    private function runRand(Internal $fn, Runtime $runtime, HashTable $array, int $num): int
    {
        $out = $this->runRandResult($fn, $runtime, $array, $num);

        return $out->toInt();
    }

    private function runRandResult(Internal $fn, Runtime $runtime, HashTable $array, int $num): VMVariable
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
