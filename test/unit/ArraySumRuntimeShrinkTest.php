<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArraySumJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_sum() AOT emits via ArraySumLlvm (caller-frame), not NestedJIT Variable ABI (#12590, #24167).
 * VM execute() still uses ArraySumJitHelper PHP.
 */
final class ArraySumRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 7680;

    public function testArraySumRuntimeUsesInlineLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArraySumRuntime.php');
        $this->assertStringContainsString('ArraySumLlvm::sum', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__array_sum__fold', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arraySum', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_sum.php');
        $this->assertStringContainsString('ArraySumRuntime::sum', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arraySum', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArraySumLlvm.php');
        $this->assertStringContainsString('ArraySumJitHelper', $llvm);
        $this->assertStringContainsString('#24167', $llvm);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeSumLlvmDeletion(): void
    {
        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function arraySum(', $arrayBuiltin);
        $this->assertStringNotContainsString('function arraySumNative', $arrayBuiltin);
        $this->assertStringNotContainsString('function arraySumHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function arraySumAccumulateLongValue', $arrayBuiltin);
        $this->assertStringNotContainsString('function arraySumAccumulateStringPtr', $arrayBuiltin);

        $lines = substr_count($arrayBuiltin, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_sum native LLVM deletion (#18133)'
        );
    }

    public function testArraySumJitHelperSumsIntegers(): void
    {
        $ht = self::intListTable(1, 2, 3);
        $out = ArraySumJitHelper::sum($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(6, $out->toInt());
    }

    public function testArraySumJitHelperPromotesToFloat(): void
    {
        $ht = new HashTable();
        foreach ([1, 2.5] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } else {
                $var->float($raw);
            }
            $ht->addIndex($i, $var);
        }
        $out = ArraySumJitHelper::sum($ht);
        $this->assertSame(Variable::TYPE_FLOAT, $out->type);
        $this->assertSame(3.5, $out->toFloat());
    }

    public function testArraySumJitHelperSkipsEnumCases(): void
    {
        $ht = self::intListTable(1, 2);
        $out = ArraySumJitHelper::sum($ht);
        $this->assertSame(3, $out->toInt());
    }

    /** @param list<int> $values */
    private static function intListTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable();
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }
}
