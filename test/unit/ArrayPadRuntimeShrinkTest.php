<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayPadJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_pad() JIT routes through ArrayPadJitHelper PHP not ArrayBuiltinHelper LLVM (#12476, #14286). */
final class ArrayPadRuntimeShrinkTest extends TestCase
{
    public function testArrayPadRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayPadRuntime.php');
        $this->assertStringContainsString('ArrayPadJitHelper', $runtime);
        $this->assertStringContainsString('padCopyLegacy', $runtime);
        $this->assertStringContainsString('padCopyTyped', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::pad', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_pad.php');
        $this->assertStringContainsString('ArrayPadRuntime::pad', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::pad', $builtin);
    }

    public function testArrayPadJitHelperMatchesVmPadCopySemantics(): void
    {
        $ht = new HashTable();
        foreach ([1, 2] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->addIndex($i, $var);
        }

        $padValue = new Variable();
        $padValue->int(0);

        $right = ArrayPadJitHelper::padCopyLegacy($ht, 4, $padValue);
        $this->assertSame(4, $right->getNumElements());
        $this->assertSame(0, $right->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertSame(0, $right->findIndex(3)?->resolveIndirect()->toInt());

        $left = ArrayPadJitHelper::padCopyLegacy($ht, -4, $padValue);
        $this->assertSame(4, $left->getNumElements());
        $this->assertSame(0, $left->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(0, $left->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(1, $left->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertSame(2, $left->findIndex(3)?->resolveIndirect()->toInt());
    }

    public function testArrayPadLeftPadPreservesAssociativeStringKeys(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->add($key, $var);
        }

        $padValue = new Variable();
        $padValue->int(0);

        $left = ArrayPadJitHelper::padCopyLegacy($ht, -4, $padValue);
        $this->assertSame(4, $left->getNumElements());
        $this->assertSame(0, $left->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(1, $left->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(2, $left->find('b')?->resolveIndirect()->toInt());
        $this->assertSame(3, $left->find('c')?->resolveIndirect()->toInt());
    }
}
