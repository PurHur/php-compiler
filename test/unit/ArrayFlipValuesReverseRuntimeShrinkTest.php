<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFlipJitHelper;
use PHPCompiler\ext\standard\ArrayReverseJitHelper;
use PHPCompiler\ext\standard\ArrayValuesJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_flip/values/reverse JIT routes through JitHelper PHP not ArrayBuiltinHelper LLVM (#12329, #14244, #17922). */
final class ArrayFlipValuesReverseRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 12400;

    public function testArrayFlipRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #26970: NestedJIT of ArrayFlipJitHelper fatals on iterateKeyed; call-site ArrayFlipLlvm.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFlipRuntime.php');
        $this->assertStringContainsString('ArrayFlipLlvm', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('ensureCompiled', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_flip.php');
        $this->assertStringContainsString('ArrayFlipRuntime::flip', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildFlipArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildFlipArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildFlipHashTable', $arrayBuiltin);
        $this->assertStringNotContainsString('function flipStorePackedEntry', $arrayBuiltin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFlipLlvm.php');
        $this->assertStringContainsString('function flipHashTable', $llvm);
        $this->assertStringContainsString('storeFlipped', $llvm);
    }

    public function testArrayValuesRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #27212: NestedJIT of ArrayValuesJitHelper returned empty under thin AOT; call-site HashTableValuesLlvm.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayValuesRuntime.php');
        $this->assertStringContainsString('HashTableValuesLlvm', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('buildValuesArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('ensureCompiled', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_values.php');
        // #27212 / #27545: call-site ArrayValuesRuntime → HashTableValuesLlvm (not NestedJIT helper).
        $this->assertStringContainsString('ArrayValuesRuntime::values', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildValuesArray', $builtin);
        $this->assertStringNotContainsString('ArrayMergeRuntime::merge', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildValuesArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildValuesFromNativeArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildValuesFromHashTable', $arrayBuiltin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableValuesLlvm.php');
        $this->assertStringContainsString('function values', $llvm);
        $this->assertStringContainsString('appendPackedValues', $llvm);
        $this->assertStringContainsString('appendStringKeyValues', $llvm);

        $nested = (string) file_get_contents(__DIR__.'/../../lib/JIT/NestedVmHashTableMethodLlvm.php');
        $this->assertStringContainsString("'valuescopy'", $nested);
    }

    public function testArrayReverseRuntimeUsesCallSiteLlvmNotNestedJitHelper(): void
    {
        // #27067: NestedJIT of ArrayReverseJitHelper fatals on HashTable::reverseCopy; call-site HashTableReverseLlvm.
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayReverseRuntime.php');
        $this->assertStringContainsString('HashTableReverseLlvm', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertStringNotContainsString('ensureCompiled', $runtime);
        $this->assertStringNotContainsString('buildReverseArray', $runtime);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_reverse.php');
        $this->assertStringContainsString('ArrayReverseRuntime::reverse', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildReverseArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildReverseArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildReverseFromNativeArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildReverseFromHashTable', $arrayBuiltin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableReverseLlvm.php');
        $this->assertStringContainsString('function reverse', $llvm);
        $this->assertStringContainsString('writeReversedEntry', $llvm);

        $nested = (string) file_get_contents(__DIR__.'/../../lib/JIT/NestedVmHashTableMethodLlvm.php');
        $this->assertStringContainsString("'reversecopy'", $nested);
    }

    public function testArrayBuiltinHelperLineBudgetAfterFlipValuesReverseLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead flip/values/reverse LLVM deletion (#17922)'
        );
    }

    public function testArrayFlipJitHelperMatchesVmArraySemantics(): void
    {
        $src = self::mapTable(['a' => 1, 'b' => 2]);
        $flipped = ArrayFlipJitHelper::flip($src);
        $this->assertSame('a', $flipped->findIndex(1)?->resolveIndirect()->toString());
        $this->assertSame('b', $flipped->findIndex(2)?->resolveIndirect()->toString());
    }

    public function testArrayValuesJitHelperMatchesHashTableValuesCopy(): void
    {
        $src = self::mapTable(['x' => 10, 'y' => 20]);
        $values = ArrayValuesJitHelper::valuesCopy($src);
        $keys = [];
        foreach ($values->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertContains($value->resolveIndirect()->toInt(), [10, 20]);
        }
        $this->assertSame([0, 1], $keys);
    }

    public function testArrayReverseJitHelperMatchesHashTableReverseCopy(): void
    {
        $src = self::listTable(1, 2, 3);
        $reversed = ArrayReverseJitHelper::reverseCopy($src, false);
        $keys = [];
        foreach ($reversed->iterateKeyed(true) as [$key, $value]) {
            $keys[] = $key->resolveIndirect()->toInt();
            $this->assertSame(Variable::TYPE_INTEGER, $value->resolveIndirect()->type);
        }
        $this->assertSame([0, 1, 2], $keys);
        $this->assertSame(3, $reversed->findIndex(0)?->resolveIndirect()->toInt());
        $this->assertSame(1, $reversed->findIndex(2)?->resolveIndirect()->toInt());
    }

    /** @param list<int> $values */
    private static function listTable(int ...$values): HashTable
    {
        $ht = new HashTable();
        foreach ($values as $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->append($var);
        }

        return $ht;
    }

    /** @param array<string, int> $pairs */
    private static function mapTable(array $pairs): HashTable
    {
        $ht = new HashTable();
        foreach ($pairs as $key => $value) {
            $var = new Variable(Variable::TYPE_INTEGER);
            $var->int($value);
            $ht->add($key, $var);
        }

        return $ht;
    }
}
