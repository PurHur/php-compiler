<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayCountValuesJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_count_values() JIT routes all operands through ArrayCountValuesJitHelper PHP not ArrayBuiltinHelper LLVM (#12331, #14485, #18232). */
final class ArrayCountValuesRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 5510;

    public function testArrayCountValuesRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayCountValuesRuntime.php');
        $this->assertStringContainsString('ArrayCountValuesJitHelper', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayCountValues', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_count_values.php');
        $this->assertStringContainsString('ArrayCountValuesRuntime::countValues', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayCountValues', $builtin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeCountValuesLlvmDeletion(): void
    {
        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function arrayCountValues(', $arrayBuiltin);
        $this->assertStringNotContainsString('function countValuesHashTable(', $arrayBuiltin);
        $this->assertStringNotContainsString('function countIncrementPackedEntry(', $arrayBuiltin);
        $this->assertStringNotContainsString('function emitCountValuesSkipWarning(', $arrayBuiltin);

        $lines = substr_count($arrayBuiltin, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_count_values native LLVM deletion (#18232)'
        );
    }

    public function testArrayCountValuesJitHelperMatchesVmArraySemantics(): void
    {
        $ht = new HashTable();
        foreach (['a', 'b', 'a'] as $value) {
            $var = new Variable(Variable::TYPE_STRING);
            $var->string($value);
            $ht->append($var);
        }
        $counts = ArrayCountValuesJitHelper::countValues($ht);
        $this->assertSame(2, $counts->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(1, $counts->find('b')?->resolveIndirect()->toInt());
    }
}
