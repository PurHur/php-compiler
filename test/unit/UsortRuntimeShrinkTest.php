<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** usort()/uksort() JIT routes through UsortJitHelper + Sort/KeySort runtimes not ArrayBuiltinHelper LLVM (#15518, #17795). */
final class UsortRuntimeShrinkTest extends TestCase
{
    private const ARRAY_BUILTIN_HELPER_MAX_LINES = 1920;

    public function testUsortRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UsortRuntime.php');
        $this->assertStringContainsString('UsortJitHelper', $runtime);
        $this->assertStringContainsString('SortRuntime::sortPacked', $runtime);
        $this->assertStringContainsString('KeySortRuntime::ksortByKey', $runtime);
        $this->assertStringContainsString('__hashtable__sortStringKeyValues', $runtime);
        $this->assertStringContainsString('sortValuesWithClosure', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPackedWithClosure', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortStringKeysWithClosure', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/UsortJitHelper.php');
        $this->assertStringContainsString('sortPackedWithClosure', $helper);
        $this->assertStringContainsString('sortKeysWithClosure', $helper);
        $this->assertStringContainsString('sortValuesWithClosure', $helper);
        // Thin AOT writeback: assignPackedList (#26954); keyed uses reorderKeyedPairs (#27217).
        $this->assertStringContainsString('$ht->assignPackedList($values)', $helper);
        $this->assertStringContainsString('$ht->reorderKeyedPairs($pairs)', $helper);
        $this->assertStringContainsString('VmClosureInvoke', $helper);

        $usort = (string) file_get_contents(__DIR__.'/../../ext/standard/usort_.php');
        $this->assertStringContainsString('UsortRuntime::usortPacked', $usort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPacked', $usort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPackedWithClosure', $usort);

        $uasort = (string) file_get_contents(__DIR__.'/../../ext/standard/uasort_.php');
        $this->assertStringContainsString('UsortRuntime::uasortValues', $uasort);
        $this->assertStringNotContainsString('SortRuntime::sortPacked', $uasort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPacked', $uasort);

        $uksort = (string) file_get_contents(__DIR__.'/../../ext/standard/uksort_.php');
        $this->assertStringContainsString('UsortRuntime::uksortKeys', $uksort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::ksortByKey', $uksort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortStringKeysWithClosure', $uksort);
    }

    public function testArrayBuiltinHelperNoLongerContainsUsortClosureLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayBuiltinHelper.php');
        $this->assertStringNotContainsString('function sortPackedWithClosure', $source);
        $this->assertStringNotContainsString('function sortStringKeysWithClosure', $source);
        $this->assertStringNotContainsString('usort_closure_outer_head', $source);
        $this->assertStringNotContainsString('uksort_closure_str_pass', $source);

        $lines = substr_count($source, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::ARRAY_BUILTIN_HELPER_MAX_LINES,
            $lines,
            'ArrayBuiltinHelper.php LOC after dead usort/uksort LLVM deletion (#17795)'
        );
    }
}
