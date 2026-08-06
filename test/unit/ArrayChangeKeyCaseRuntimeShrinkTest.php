<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * array_change_key_case() AOT/JIT uses HashTableChangeKeyCaseLlvm; VM SSOT remains
 * ArrayChangeKeyCaseJitHelper (#12371, #14530, #18024, #27183).
 */
final class ArrayChangeKeyCaseRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 8801;

    public function testArrayChangeKeyCaseRuntimeUsesHashTableChangeKeyCaseLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayChangeKeyCaseRuntime.php');
        $this->assertStringContainsString('HashTableChangeKeyCaseLlvm', $runtime);
        $this->assertStringContainsString('ArrayChangeKeyCaseJitHelper', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChangeKeyCaseArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/HashTableChangeKeyCaseLlvm.php');
        $this->assertStringContainsString('transformAllAsciiDynamic', $llvm);
        $this->assertStringContainsString('setAtStringKey', $llvm);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/array_change_key_case.php');
        $this->assertStringContainsString('ArrayChangeKeyCaseRuntime::changeKeyCase', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChangeKeyCaseArray', $builtin);

        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function buildChangeKeyCaseArray', $arrayBuiltin);
        $this->assertStringNotContainsString('function buildChangeKeyCaseHashTable', $arrayBuiltin);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeChangeKeyCaseLlvmDeletion(): void
    {
        $lines = substr_count(
            (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php'),
            "\n"
        ) + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead array_change_key_case native LLVM deletion (#18024)'
        );
    }

    public function testArrayChangeKeyCaseJitHelperMatchesVmArraySemantics(): void
    {
        $ht = new HashTable();
        $val = new Variable(Variable::TYPE_INTEGER);
        $val->int(1);
        $ht->add('Foo', $val);
        $ht->addIndex(2, $val);

        $lower = ArrayChangeKeyCaseJitHelper::changeKeyCase($ht, StdlibConstants::CASE_LOWER);
        $this->assertSame(1, $lower->find('foo')?->resolveIndirect()->toInt());
        $this->assertSame(1, $lower->findIndex(2)?->resolveIndirect()->toInt());

        $upper = ArrayChangeKeyCaseJitHelper::changeKeyCase($ht, StdlibConstants::CASE_UPPER);
        $this->assertSame(1, $upper->find('FOO')?->resolveIndirect()->toInt());
    }
}
