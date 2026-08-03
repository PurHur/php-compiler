<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayProductJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_product() AOT emits via ArrayProductLlvm (caller-frame), not NestedJIT Variable ABI (#12591, #26968).
 * VM execute() still uses ArrayProductJitHelper PHP.
 */
final class ArrayProductRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 6900;

    public function testArrayProductRuntimeUsesInlineLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayProductRuntime.php');
        $this->assertStringContainsString('ArrayProductLlvm::product', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__array_product__fold', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayProduct', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_product.php');
        $this->assertStringContainsString('ArrayProductRuntime::product', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::arrayProduct', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayProductLlvm.php');
        $this->assertStringContainsString('ArrayProductJitHelper', $llvm);
        $this->assertStringContainsString('#26968', $llvm);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeProductLlvmDeletion(): void
    {
        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function arrayProduct(', $arrayBuiltin);
        $this->assertStringNotContainsString('function arrayProductNative', $arrayBuiltin);
        $this->assertStringNotContainsString('function arrayProductHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function arrayProductAccumulateLongValue', $arrayBuiltin);
        $this->assertStringNotContainsString('function arrayProductAccumulateStringPtr', $arrayBuiltin);

        $lines = substr_count($arrayBuiltin, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_product native LLVM deletion (#18141)'
        );
    }

    public function testArrayProductJitHelperMultipliesIntegers(): void
    {
        $ht = self::intListTable(2, 3, 4);
        $out = ArrayProductJitHelper::product($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(24, $out->toInt());
    }

    public function testArrayProductJitHelperEmptyArrayReturnsOne(): void
    {
        $ht = new HashTable();
        $out = ArrayProductJitHelper::product($ht);
        $this->assertSame(Variable::TYPE_INTEGER, $out->type);
        $this->assertSame(1, $out->toInt());
    }

    public function testArrayProductJitHelperPromotesToFloat(): void
    {
        $ht = new HashTable();
        foreach ([2, 2.5] as $i => $raw) {
            $var = new Variable();
            if (\is_int($raw)) {
                $var->int($raw);
            } else {
                $var->float($raw);
            }
            $ht->addIndex($i, $var);
        }
        $out = ArrayProductJitHelper::product($ht);
        $this->assertSame(Variable::TYPE_FLOAT, $out->type);
        $this->assertSame(5.0, $out->toFloat());
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
