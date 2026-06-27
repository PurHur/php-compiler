<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayChunkJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_chunk() JIT routes through ArrayChunkJitHelper PHP not ArrayBuiltinHelper LLVM (#12455). */
final class ArrayChunkRuntimeShrinkTest extends TestCase
{
    public function testArrayChunkRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayChunkRuntime.php');
        $this->assertStringContainsString('ArrayChunkJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildChunkArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_chunk.php');
        $this->assertStringContainsString('ArrayChunkRuntime::chunk', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChunkArray', $builtin);
    }

    public function testArrayChunkJitHelperMatchesVmChunkCopySemantics(): void
    {
        $ht = new HashTable();
        foreach ([1, 2, 3, 4, 5] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->addIndex($i, $var);
        }

        $chunks = ArrayChunkJitHelper::chunkCopy($ht, 2, false);
        $this->assertSame(3, $chunks->getNumElements());
        $outer = $chunks->findIndex(0)?->resolveIndirect()->toArray();
        $this->assertNotNull($outer);
        $this->assertSame(1, $outer->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(2, $outer->findIndex(1)?->resolveIndirect()->toInt());

        $preserve = ArrayChunkJitHelper::chunkCopy($ht, 2, true);
        $first = $preserve->findIndex(0)?->resolveIndirect()->toArray();
        $this->assertNotNull($first);
        $this->assertSame(1, $first->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(2, $first->findIndex(1)?->resolveIndirect()->toInt());
    }
}
