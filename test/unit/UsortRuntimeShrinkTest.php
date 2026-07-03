<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** usort()/uksort() JIT routes through UsortJitHelper + Sort/KeySort runtimes not ArrayBuiltinHelper LLVM (#15518). */
final class UsortRuntimeShrinkTest extends TestCase
{
    public function testUsortRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UsortRuntime.php');
        $this->assertStringContainsString('UsortJitHelper', $runtime);
        $this->assertStringContainsString('SortRuntime::sortPacked', $runtime);
        $this->assertStringContainsString('KeySortRuntime::ksortByKey', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPackedWithClosure', $runtime);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortStringKeysWithClosure', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/UsortJitHelper.php');
        $this->assertStringContainsString('sortPackedWithClosure', $helper);
        $this->assertStringContainsString('sortKeysWithClosure', $helper);
        $this->assertStringContainsString('VmClosureCall', $helper);

        $usort = (string) file_get_contents(__DIR__.'/../../ext/standard/usort_.php');
        $this->assertStringContainsString('UsortRuntime::usortPacked', $usort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPacked', $usort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPackedWithClosure', $usort);

        $uasort = (string) file_get_contents(__DIR__.'/../../ext/standard/uasort_.php');
        $this->assertStringContainsString('SortRuntime::sortPacked', $uasort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortPacked', $uasort);

        $uksort = (string) file_get_contents(__DIR__.'/../../ext/standard/uksort_.php');
        $this->assertStringContainsString('UsortRuntime::uksortKeys', $uksort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::ksortByKey', $uksort);
        $this->assertStringNotContainsString('ArrayBuiltinHelper::sortStringKeysWithClosure', $uksort);
    }
}
