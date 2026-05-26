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
    public function testShufflesPackedStringsInPlace(): void
    {
        $runtime = new Runtime();
        $fn = new shuffle_();
        $ht = new HashTable();
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $v) {
            $val = new VMVariable();
            $val->string($v);
            $ht->addIndex($i, $val);
        }
        $sig = $this->packedStringSig($ht);
        $this->runShuffle($fn, $runtime, $ht);
        $this->assertSame($sig, $this->packedStringSig($ht));
        $this->assertSame(5, $ht->getNumElements());
    }

    public function testShufflesPackedIntegersInPlace(): void
    {
        $runtime = new Runtime();
        $fn = new shuffle_();
        $ht = new HashTable();
        foreach ([1, 2, 3, 4] as $i => $v) {
            $val = new VMVariable();
            $val->int($v);
            $ht->addIndex($i, $val);
        }
        $sig = $this->packedIntSig($ht);
        $this->runShuffle($fn, $runtime, $ht);
        $this->assertSame($sig, $this->packedIntSig($ht));
    }

    public function testReturnsTrueForEmptyList(): void
    {
        $runtime = new Runtime();
        $fn = new shuffle_();
        $ht = new HashTable();
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($ht);
        $frame->calledArgs = [$arrayVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
    }

    private function runShuffle(Internal $fn, Runtime $runtime, HashTable $array): void
    {
        $frame = $fn->getFrame($runtime->vmContext);
        $arrayVar = new VMVariable();
        $arrayVar->array($array);
        $frame->calledArgs = [$arrayVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $this->assertTrue($frame->returnVar->resolveIndirect()->toBool());
    }

    private function packedStringSig(HashTable $ht): string
    {
        $parts = [];
        foreach ($ht->iterate(true) as $v) {
            $parts[] = $v->toString();
        }
        sort($parts);

        return implode(',', $parts);
    }

    private function packedIntSig(HashTable $ht): string
    {
        $parts = [];
        foreach ($ht->iterate(true) as $v) {
            $parts[] = (string) $v->toInt();
        }
        sort($parts);

        return implode(',', $parts);
    }
}
