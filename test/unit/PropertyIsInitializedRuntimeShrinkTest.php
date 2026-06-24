<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\PropertyIsInitializedJitHelper;
use PHPCompiler\VM\Variable as VmVariable;
use PHPUnit\Framework\TestCase;

/** PropertyIsInitialized JIT routes slot guard through PropertyIsInitializedJitHelper PHP (#10186). */
final class PropertyIsInitializedRuntimeShrinkTest extends TestCase
{
    public function testPropertyIsInitializedHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/PropertyIsInitializedHelper.php');
        $this->assertStringContainsString('PropertyIsInitializedLlvm::lower', $helper);
        $this->assertStringNotContainsString('emitSlotInitialized', $helper);
        $this->assertLessThan(25, substr_count($helper, "\n"));
    }

    public function testPropertyIsInitializedLlvmUsesJitHelper(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/PropertyIsInitializedLlvm.php');
        $this->assertStringContainsString('PropertyIsInitializedJitHelper', $llvm);
        $this->assertStringContainsString('JitVmHelperLink', $llvm);
        $this->assertStringNotContainsString('VmVariable::TYPE_UNDEFINED', $llvm);
    }

    public function testPropertyIsInitializedJitHelperValueBoxSlotIsInitialized(): void
    {
        $this->assertFalse(
            PropertyIsInitializedJitHelper::valueBoxSlotIsInitialized(VmVariable::TYPE_UNDEFINED)
        );
        $this->assertTrue(
            PropertyIsInitializedJitHelper::valueBoxSlotIsInitialized(VmVariable::TYPE_NULL)
        );
        $this->assertTrue(
            PropertyIsInitializedJitHelper::valueBoxSlotIsInitialized(VmVariable::TYPE_INTEGER)
        );
    }
}
