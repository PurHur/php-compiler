<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFilterJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_filter() AOT: no-callback uses ArrayFilterLlvm; closures use packed LLVM (#12370, #32672, #34897).
 * NestedJIT ArrayFilterJitHelper remains for VM helper unit checks only.
 */
final class ArrayFilterRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 13480;

    public function testArrayFilterRuntimeUsesLlvmDefaultNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFilterRuntime.php');
        $this->assertStringContainsString('ArrayFilterLlvm::filterDefault', $runtime);
        $this->assertStringContainsString('ArrayFilterLlvm::filterPackedWithClosure', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayFilterJitHelper', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__array_filter__default', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildFilterArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFilterLlvm.php');
        $this->assertStringContainsString('function filterDefault', $llvm);
        $this->assertStringContainsString('HashTableExportKeyValuePairs', $llvm);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_filter.php');
        $this->assertStringContainsString('ArrayFilterRuntime::filterDefault', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildFilterArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildFilterArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildFilterFromNativeArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildFilterFromHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function listEntryTruthy', $arrayBuiltin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterFilterLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_filter LLVM deletion (#17852)'
        );
    }

    public function testArrayFilterJitHelperMatchesVmFilterDefaultSemantics(): void
    {
        $ht = new HashTable();
        foreach ([0, 'x', '', false, 1] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } elseif (\is_string($raw)) {
                $var->string($raw);
            } else {
                $var->bool($raw);
            }
            $ht->addIndex($i, $var);
        }
        $filtered = ArrayFilterJitHelper::filterDefault($ht);
        $this->assertSame('x', $filtered->findIndex(1)?->resolveIndirect()->toString());
        $this->assertSame(1, $filtered->findIndex(4)?->resolveIndirect()->toInt());
        $this->assertNull($filtered->findIndex(0));
        $this->assertNull($filtered->findIndex(2));
    }
}
