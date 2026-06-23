<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmEmpty;
use PHPUnit\Framework\TestCase;

/** EmptyObjectProperty routes LLVM through EmptyObjectPropertyLlvm + VmEmpty PHP (#10268). */
final class EmptyObjectPropertyRuntimeShrinkTest extends TestCase
{
    public function testEmptyObjectPropertyHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/EmptyObjectPropertyHelper.php');
        $this->assertStringContainsString('EmptyObjectPropertyLlvm::compile', $helper);
        $this->assertStringNotContainsString('compileEmptyFromFetchedValue', $helper);
        $this->assertLessThan(45, substr_count($helper, "\n"));
    }

    public function testEmptyObjectPropertyLlvmUsesVmEmptyGuards(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/EmptyObjectPropertyLlvm.php');
        $this->assertStringContainsString('VmEmpty::', $llvm);
        $this->assertStringContainsString('VmIsset::literalStringKey', $llvm);
        $this->assertStringNotContainsString('private static function literalStringKey', $llvm);
    }

    public function testVmEmptyUninitializedSlot(): void
    {
        $this->assertTrue(VmEmpty::uninitializedSlotCountsAsEmpty(\PHPCompiler\VM\Variable::TYPE_UNDEFINED));
        $this->assertFalse(VmEmpty::uninitializedSlotCountsAsEmpty(\PHPCompiler\VM\Variable::TYPE_NULL));
    }
}
