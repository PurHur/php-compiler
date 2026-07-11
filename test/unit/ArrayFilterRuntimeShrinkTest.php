<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFilterJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_filter() JIT routes all operands through ArrayFilterJitHelper PHP not ArrayBuiltinHelper LLVM (#12370, #14386, #17852). */
final class ArrayFilterRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 13480;

    public function testArrayFilterRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFilterRuntime.php');
        $this->assertStringContainsString('ArrayFilterJitHelper', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildFilterArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

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
