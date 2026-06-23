<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmIsset;
use PHPUnit\Framework\TestCase;

/** IssetHelper routes LLVM through IssetHelperLlvm + VmIsset PHP guards (#10170). */
final class IssetHelperRuntimeShrinkTest extends TestCase
{
    public function testIssetHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/IssetHelper.php');
        $this->assertStringContainsString('IssetHelperLlvm::compile', $helper);
        $this->assertStringNotContainsString('compileHashTableOffsetIsSet', $helper);
        $this->assertStringNotContainsString('compileVariableIsSet', $helper);
        $this->assertLessThan(50, substr_count($helper, "\n"));
    }

    public function testIssetHelperLlvmUsesVmIssetGuards(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/IssetHelperLlvm.php');
        $this->assertStringContainsString('VmIsset::', $llvm);
        $this->assertStringNotContainsString('private static function literalStringKey', $llvm);
        $this->assertStringNotContainsString('private static function superglobalName', $llvm);
    }

    public function testVmIssetStoredPropertyIsSet(): void
    {
        $defined = new \PHPCompiler\VM\Variable();
        $defined->int(1);
        $this->assertTrue(VmIsset::storedPropertyIsSet($defined));

        $null = new \PHPCompiler\VM\Variable();
        $null->null();
        $this->assertFalse(VmIsset::storedPropertyIsSet($null));

        $undef = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_UNDEFINED);
        $this->assertFalse(VmIsset::storedPropertyIsSet($undef));
    }
}
