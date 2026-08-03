<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayChunkJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_chunk() JIT routes all operands through ArrayChunkJitHelper PHP not ArrayBuiltinHelper native LLVM (#12455, #14289, #17951). */
final class ArrayChunkRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 9690;

    public function testArrayChunkRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayChunkRuntime.php');
        $this->assertStringContainsString('ArrayChunkJitHelper', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChunkArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_chunk.php');
        $this->assertStringContainsString('ArrayChunkRuntime::chunk', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChunkArray', $builtin);
        // Same-namespace JitIntdiv — not PHPCompiler\JIT\JitIntdiv (#27074 / peer #26997).
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\JitIntdiv', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildChunkArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildChunkFromNativeArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildChunkPreserveKeysFromNativeArray', $arrayBuiltin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeChunkLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_chunk native LLVM deletion (#17951)'
        );
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
