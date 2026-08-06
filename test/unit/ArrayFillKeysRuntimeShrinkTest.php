<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayFillKeysJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_fill_keys() AOT/JIT uses HashTableFillKeysLlvm; VM SSOT remains ArrayFillKeysJitHelper (#12487, #27127). */
final class ArrayFillKeysRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 1920;

    public function testArrayFillKeysRuntimeUsesHashTableFillKeysLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFillKeysRuntime.php');
        $this->assertStringContainsString('HashTableFillKeysLlvm', $runtime);
        $this->assertStringContainsString('ArrayFillKeysJitHelper', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::fillKeys', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableFillKeysLlvm.php');
        $this->assertStringContainsString('HashTableCombineLlvm::storeCombineKey', $llvm);
        $this->assertStringContainsString('HashTableValuesLlvm::values', $llvm);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_fill_keys.php');
        $this->assertStringContainsString('ArrayFillKeysRuntime::fillKeys', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::fillKeys', $builtin);
    }

    public function testArrayFillKeysJitHelperMatchesVmFillKeysSemantics(): void
    {
        $keys = new HashTable();
        $a = new Variable();
        $a->string('foo');
        $keys->addIndex(0, $a);
        $b = new Variable();
        $b->string('bar');
        $keys->addIndex(1, $b);
        $fill = new Variable();
        $fill->string('baz');

        $out = ArrayFillKeysJitHelper::fillKeysCopy($keys, $fill);
        $assoc = [];
        foreach ($out->iterateKeyed(true) as [$key, $val]) {
            $assoc[Variable::TYPE_STRING === $key->type ? $key->toString() : $key->toInt()] = $val->toString();
        }
        $this->assertSame(['foo' => 'baz', 'bar' => 'baz'], $assoc);
    }

    public function testArrayBuiltinHelperLineBudgetAfterFillKeysLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead fill_keys LLVM deletion (#18407)'
        );
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function fillKeys(', $source);
    }
}
