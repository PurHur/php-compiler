<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ListSpreadTailJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** List spread tail JIT routes through ListSpreadTailJitHelper PHP not ArrayBuiltinHelper LLVM (#18446). */
final class ListSpreadTailRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 920;

    public function testListSpreadTailRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ListSpreadTailRuntime.php');
        $this->assertStringContainsString('ListSpreadTailJitHelper', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildCopyListSpreadTail', $runtime);

        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('ListSpreadTailRuntime::copyTail', $jit);
        $this->assertStringContainsString('ArraySliceRuntime::slice', $jit);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildCopyListSpreadTail', $jit);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildSliceArray', $jit);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildSliceArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildCopyListSpreadTail', $arrayBuiltin);
        $this->assertStringNotContainsString('function storeCombinedEntry', $arrayBuiltin);
        $this->assertStringNotContainsString('function storeValueEntryAtIndex', $arrayBuiltin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterListSpreadLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after list-spread + dead combine LLVM deletion (#18446)'
        );
    }

    public function testListSpreadTailJitHelperMatchesVmSemantics(): void
    {
        $ht = new HashTable();
        foreach (['a' => 1, 'b' => 2, 'c' => 3] as $key => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->add((string) $key, $var);
        }
        foreach ([10, 20, 30] as $i => $raw) {
            $var = new Variable();
            $var->int($raw);
            $ht->addIndex($i, $var);
        }

        $excluded = ListSpreadTailJitHelper::excludedKeysTable(['b']);
        $tail = ListSpreadTailJitHelper::copyTail($ht, 1, $excluded);

        $this->assertSame(1, $tail->find('a')?->resolveIndirect()->toInt());
        $this->assertNull($tail->find('b'));
        $this->assertSame(3, $tail->find('c')?->resolveIndirect()->toInt());
        $this->assertSame(20, $tail->findIndex(1)?->resolveIndirect()->toInt());
        $this->assertSame(30, $tail->findIndex(2)?->resolveIndirect()->toInt());
        $this->assertNull($tail->findIndex(0));
    }
}
