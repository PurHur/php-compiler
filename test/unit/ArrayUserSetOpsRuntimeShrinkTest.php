<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_udiff()/array_uintersect()/ukey/uassoc JIT routes (#18515, #27228, #27243, #27533). */
final class ArrayUserSetOpsRuntimeShrinkTest extends TestCase
{
    private const JIT_HELPER_MAX_LINES = 120;

    public function testJitArrayUserSetOpsDelegatesToArrayUserSetOpsRuntime(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitArrayUserSetOps.php');
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::diffByValue', $helper);
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::diffByKey', $helper);
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::intersectByKey', $helper);
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::diffByKeyValue', $helper);
        $this->assertStringContainsString('ArrayUserSetOpsRuntime::intersectByKeyValue', $helper);
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

    public function testArrayUserSetOpsRuntimeUsesPureLlvmValueKeyUassoc(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayUserSetOpsRuntime.php');
        $this->assertStringContainsString('ArrayUserSetOpsValueLlvm::filterByValue', $runtime);
        $this->assertStringContainsString('ArrayUserSetOpsKeyLlvm::filterByKey', $runtime);
        $this->assertStringContainsString('ArrayUserSetOpsUassocLlvm::filterByKeyValue', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope', $runtime);
        $this->assertStringNotContainsString('diffByValueWithClosure', $runtime);
        $this->assertStringNotContainsString('ArrayUserSetOpsJitHelper', $runtime);
    }

    public function testArrayUserSetOpsValueLlvmAvoidsNestedClosureInvoke(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayUserSetOpsValueLlvm.php');
        $this->assertStringContainsString('ArrayUserSetOpsKeyLlvm::compareValueBoxesPublic', $llvm);
        $this->assertStringNotContainsString('new NestedClosureInvoke', $llvm);
        $this->assertStringNotContainsString('NestedClosureInvokeLlvm', $llvm);
        $this->assertStringNotContainsString('JitVmHelperLink', $llvm);
    }

    public function testArrayUserSetOpsJitHelperDelegatesToVmClosureCall(): void
    {
        // Host/VM SSOT retained; thin AOT uses ArrayUserSetOpsValueLlvm (#27533).
        $jitHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ArrayUserSetOpsJitHelper.php');
        $this->assertStringContainsString('VmClosureCall::invokeTwoForUserCompare', $jitHelper);
        $this->assertStringContainsString('Superglobals::getActiveContext', $jitHelper);
        $this->assertStringNotContainsString('__hashtable__', $jitHelper);
    }
}
