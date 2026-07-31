<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper;
use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** array_change_key_case() JIT routes all operands through ArrayChangeKeyCaseJitHelper PHP not ArrayBuiltinHelper native LLVM (#12371, #14530, #18024). */
final class ArrayChangeKeyCaseRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 8801;

    public function testArrayChangeKeyCaseRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayChangeKeyCaseRuntime.php');
        $this->assertStringContainsString('ArrayChangeKeyCaseJitHelper', $runtime);
        $this->assertStringContainsString('loadHashTable', $runtime);
        $this->assertStringNotContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::buildChangeKeyCaseArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

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
