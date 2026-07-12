<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySliceJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_slice() JIT routes all operands through ArraySliceJitHelper PHP not ArrayBuiltinHelper native LLVM (#12410, #14285, #17936). */
final class ArraySliceRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 12110;

    public function testArraySliceRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySliceRuntime.php');
        $this->assertStringContainsString('ArraySliceJitHelper', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSliceArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_slice.php');
        $this->assertStringContainsString('ArraySliceRuntime::slice', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSliceArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildSliceFromNativeArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildSlicePreserveKeysFromNativeArray', $arrayBuiltin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeSliceLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_slice native LLVM deletion (#17936)'
        );
    }

    public function testArraySliceJitHelperMatchesVmSliceCopySemantics(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->add((string) $key, $var);
        }

        $sliced = ArraySliceJitHelper::sliceCopy($ht, 1, true, 1, true);
        $this->assertSame(2, $sliced->find('b')?->resolveIndirect()->toInt());
        $this->assertNull($sliced->find('a'));
        $this->assertNull($sliced->find('c'));

        $packed = new HashTable();
        foreach ([10, 20, 30, 40] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $packed->addIndex($i, $var);
        }
        $reindexed = ArraySliceJitHelper::sliceCopy($packed, 1, true, 2, false);
        $this->assertSame(20, $reindexed->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(30, $reindexed->findIndex(1)?->resolveIndirect()->toInt());
    }
}
