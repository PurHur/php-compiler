<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayCombineJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_combine() JIT uses HashTableCombineLlvm call-site (not NestedJIT HashTable return)
 * (#12502, #14437, #18013, #27132).
 */
final class ArrayCombineRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 920;

    public function testArrayCombineRuntimeUsesHashTableCombineLlvmNotNestedJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayCombineRuntime.php');
        $this->assertStringContainsString('HashTableCombineLlvm', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ensureBridge', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::combine', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $combineLlvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableCombineLlvm.php');
        $this->assertStringContainsString('zipPacked', $combineLlvm);
        $this->assertStringContainsString('storeCombineKey', $combineLlvm);
        $this->assertStringContainsString('ArrayFlipLlvm', $combineLlvm);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_combine.php');
        $this->assertStringContainsString('ArrayCombineRuntime::combine', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::combine', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function combine(', $arrayBuiltin);
        $this->assertStringNotContainsString('function combineHashTablesInto', $arrayBuiltin);
        $this->assertStringNotContainsString('function combineNativeArrays', $arrayBuiltin);

        $jsonFold = (string) file_get_contents(__DIR__.'/../../ext/standard/JitJsonEncodeCompileTime.php');
        $this->assertStringContainsString('tryCompileTimeArrayFromArrayCombine', $jsonFold);
        $this->assertStringContainsString('#27132', $jsonFold);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeCombineLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_combine native LLVM deletion (#18013)'
        );
    }

    public function testArrayCombineJitHelperMatchesCombineSemantics(): void
    {
        $keys = new HashTable();
        $k0 = new Variable();
        $k0->string('a');
        $keys->addIndex(0, $k0);
        $k1 = new Variable();
        $k1->string('b');
        $keys->addIndex(1, $k1);

        $values = new HashTable();
        $v0 = new Variable();
        $v0->int(1);
        $values->addIndex(0, $v0);
        $v1 = new Variable();
        $v1->int(2);
        $values->addIndex(1, $v1);

        $out = ArrayCombineJitHelper::combineCopy($keys, $values);
        $this->assertSame(1, $out->find('a')?->resolveIndirect()->toInt());
        $this->assertSame(2, $out->find('b')?->resolveIndirect()->toInt());
    }

    public function testArrayCombineJitHelperDuplicateKeysKeepLast(): void
    {
        $keys = new HashTable();
        $k0 = new Variable();
        $k0->string('k');
        $keys->addIndex(0, $k0);
        $k1 = new Variable();
        $k1->string('k');
        $keys->addIndex(1, $k1);

        $values = new HashTable();
        $v0 = new Variable();
        $v0->int(1);
        $values->addIndex(0, $v0);
        $v1 = new Variable();
        $v1->int(2);
        $values->addIndex(1, $v1);

        $out = ArrayCombineJitHelper::combineCopy($keys, $values);
        $this->assertSame(1, $out->getNumElements());
        $this->assertSame(2, $out->find('k')?->resolveIndirect()->toInt());
    }
}
