<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\VmFiberValue;
use PHPUnit\Framework\TestCase;

/** FiberHelper routes LLVM through FiberHelperLlvm + VmFiberValue PHP guards (#10079). */
final class FiberHelperRuntimeShrinkTest extends TestCase
{
    public function testFiberHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/FiberHelper.php');
        $this->assertStringContainsString('FiberHelperLlvm::compileResumeFunction', $helper);
        $this->assertStringNotContainsString('emitPendingThrowGate', $helper);
        $this->assertStringNotContainsString('branchSwitch', $helper);
        $this->assertLessThan(120, substr_count($helper, "\n"));
    }

    public function testFiberHelperLlvmUsesVmFiberValueGuards(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/FiberHelperLlvm.php');
        $this->assertStringContainsString('VmFiberValue::', $llvm);
        $this->assertStringNotContainsString('Unsupported fiber value type in JIT', $llvm);
    }

    public function testVmFiberValueWriteFunctionMapping(): void
    {
        $this->assertSame('__value__writeString', VmFiberValue::writeFunctionForJitType(JitVariable::TYPE_STRING));
        $this->assertSame('__value__writeLong', VmFiberValue::writeFunctionForJitType(JitVariable::TYPE_NATIVE_LONG));
        $this->assertSame('__value__writeNull', VmFiberValue::writeFunctionForJitType(JitVariable::TYPE_NULL));
        $this->assertNull(VmFiberValue::writeFunctionForJitType(JitVariable::TYPE_OBJECT));
        $this->assertTrue(VmFiberValue::isValueBoxCopy(JitVariable::TYPE_VALUE));
    }
}
