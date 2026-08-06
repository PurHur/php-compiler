<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_udiff()/array_uintersect()/array_diff_ukey()/array_intersect_ukey() JIT routes through ArrayUserSetOpsJitHelper PHP (#18515, #27228). */
final class ArrayUserSetOpsRuntimeShrinkTest extends TestCase
{
    private const JIT_HELPER_MAX_LINES = 70;

    public function testJitArrayUserSetOpsDelegatesToArrayUserSetOpsRuntime(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitArrayUserSetOps.php');
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::diffByValue', $helper);
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::diffByKey', $helper);
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::intersectByKey', $helper);
        $this->assertStringNotContainsString('filterFirstHashTableByValueCompare', $helper);
        $this->assertStringNotContainsString('scanHashTableValuesWithClosure', $helper);
        $this->assertStringNotContainsString('closureCompareToI32', $helper);

        $lines = substr_count($helper, "\n") + 1;
        $this->assertLessThanOrEqual(
            self::JIT_HELPER_MAX_LINES,
            $lines,
            'JitArrayUserSetOps.php should be a thin trampoline after #18515'
        );
    }

    public function testArrayUserSetOpsRuntimeLinksJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayUserSetOpsRuntime.php');
        $this->assertStringContainsString('ArrayUserSetOpsJitHelper', $runtime);
        $this->assertStringContainsString('diffByValueWithClosure', $runtime);
        $this->assertStringContainsString('intersectByValueWithClosure', $runtime);
        $this->assertStringContainsString('ArrayUserSetOpsKeyLlvm::filterByKey', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope', $runtime);
    }

    public function testArrayUserSetOpsJitHelperDelegatesToVmClosureCall(): void
    {
        $jitHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayUserSetOpsJitHelper.php');
        $this->assertStringContainsString('VmClosureCall::invokeTwoForUserCompare', $jitHelper);
        $this->assertStringContainsString('Superglobals::getActiveContext', $jitHelper);
        $this->assertStringNotContainsString('__hashtable__', $jitHelper);
    }
}
