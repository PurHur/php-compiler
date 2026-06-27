<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySliceJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_slice() JIT routes through ArraySliceJitHelper PHP not ArrayBuiltinHelper LLVM (#12410). */
final class ArraySliceRuntimeShrinkTest extends TestCase
{
    public function testArraySliceRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySliceRuntime.php');
        $this->assertStringContainsString('ArraySliceJitHelper', $runtime);
        $this->assertStringContainsString('ArrayBuiltinHelper::buildSliceArray', $runtime);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_slice.php');
        $this->assertStringContainsString('ArraySliceRuntime::slice', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSliceArray', $builtin);
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
