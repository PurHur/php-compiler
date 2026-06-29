<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFillJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_fill() JIT routes through ArrayFillJitHelper PHP not HashTableHelper LLVM (#13501). */
final class ArrayFillRuntimeShrinkTest extends TestCase
{
    public function testArrayFillRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFillRuntime.php');
        $this->assertStringContainsString('ArrayFillJitHelper', $runtime);
        $this->assertStringContainsString('HashTableHelper::buildArrayFill', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_fill.php');
        $this->assertStringContainsString('ArrayFillRuntime::fill', $builtin);
        $this->assertStringNotContainsString('HashTableHelper::buildArrayFill', $builtin);
    }

    public function testArrayFillJitHelperMatchesVmFillSemantics(): void
    {
        $value = new Variable();
        $value->int(7);

        $filled = ArrayFillJitHelper::fillCopy(2, 3, $value);
        $this->assertSame(3, $filled->getNumElements());
        $this->assertSame(7, $filled->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertSame(7, $filled->findIndex(3)?->resolveIndirect()->toInt());
        $this->assertSame(7, $filled->findIndex(4)?->resolveIndirect()->toInt());

        $empty = ArrayFillJitHelper::fillCopy(0, 0, $value);
        $this->assertInstanceOf(HashTable::class, $empty);
        $this->assertSame(0, $empty->getNumElements());
    }
}
