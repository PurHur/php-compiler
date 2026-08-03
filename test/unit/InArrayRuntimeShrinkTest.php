<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\InArrayJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * in_array() AOT emits via InArrayLlvm (caller-frame), not NestedJIT VmArray stub (#12503, #27120).
 * VM execute() still uses InArrayJitHelper → VmArray::contains.
 */
final class InArrayRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 6200;

    public function testInArrayRuntimeUsesInlineLlvmNotNestedJitBridge(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/InArrayRuntime.php');
        $this->assertStringContainsString('InArrayLlvm::contains', $runtime);
        $this->assertStringContainsString('nativeListToHashTable', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
        $this->assertStringNotContainsString('__in_array__contains', $runtime);
        $this->assertStringNotContainsString('InArrayNativeLlvm', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::inArray', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/InArrayNativeLlvm.php');

        $inOp = (string) file_get_contents(__DIR__.'/../../lib/JIT/InOperatorHelper.php');
        $this->assertStringContainsString('InArrayRuntime::inArray', $inOp);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::inArray', $inOp);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/in_array.php');
        $this->assertStringContainsString('InArrayRuntime::inArray', $builtin);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::inArray', $builtin);

        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/InArrayLlvm.php');
        $this->assertStringContainsString('InArrayJitHelper', $llvm);
        $this->assertStringContainsString('#27120', $llvm);
        $this->assertStringContainsString('VmValueCompare::identicalValueToValue', $llvm);
    }

    public function testArrayBuiltinHelperLineBudgetAfterNativeInArrayLlvmDeletion(): void
    {
        $arrayBuiltin = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function inArray(', $arrayBuiltin);
        $this->assertStringNotContainsString('function arraySearch(', $arrayBuiltin);
        $this->assertStringNotContainsString('function entryMatchesNeedle(', $arrayBuiltin);
        $this->assertStringNotContainsString('function valuesEqual(', $arrayBuiltin);
        $this->assertStringNotContainsString('function sameTypeEqual(', $arrayBuiltin);

        $lines = substr_count($arrayBuiltin, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead in_array/array_search native LLVM deletion (#18153)'
        );
    }

    public function testInArrayJitHelperLooseMatch(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('1');
        $haystack->addIndex(0, $v);

        $needle = new Variable();
        $needle->int(1);

        $this->assertTrue(InArrayJitHelper::contains($needle, $haystack, false));
        $this->assertFalse(InArrayJitHelper::contains($needle, $haystack, true));
    }

    public function testInArrayJitHelperStrictStringMatch(): void
    {
        $haystack = new HashTable();
        $v = new Variable();
        $v->string('foo');
        $haystack->addIndex(0, $v);

        $needle = new Variable();
        $needle->string('foo');

        $this->assertTrue(InArrayJitHelper::contains($needle, $haystack, true));
    }
}
