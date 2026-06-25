<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** JIT operand/compare helpers route through lib/VM SSOT (#9976). */
final class JitOperandHelpersRuntimeShrinkTest extends TestCase
{
    public function testJitFloatCompareIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitFloatCompare.php');
        $this->assertStringContainsString('VmFloatCompare', $source);
        $this->assertStringNotContainsString('eitherOperandIsNaN', $source);
        $this->assertLessThanOrEqual(35, substr_count($source, "\n") + 1);
    }

    public function testVmFloatCompareOwnsNanLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmFloatCompare.php');
        $this->assertStringContainsString('eitherOperandIsNaN', $source);
        $this->assertStringContainsString('spaceship', $source);
        $this->assertGreaterThan(70, substr_count($source, "\n") + 1);
    }

    public function testJitResourceIdStringIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitResourceIdString.php');
        $this->assertStringContainsString('VmResourceIdString', $source);
        $this->assertStringNotContainsString('snprintf', $source);
        $this->assertLessThanOrEqual(25, substr_count($source, "\n") + 1);
    }

    public function testVmResourceIdStringOwnsFormatting(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmResourceIdString.php');
        $this->assertStringContainsString('snprintf', $source);
        $this->assertStringContainsString('VmValueCompare', $source);
        $this->assertGreaterThan(70, substr_count($source, "\n") + 1);
    }

    public function testJitPowNumericOperandGuardIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitPowNumericOperandGuard.php');
        $this->assertStringContainsString('VmPowNumericOperandGuard', $source);
        $this->assertStringNotContainsString('emitRuntimeStringPtrGuard', $source);
        $this->assertLessThanOrEqual(25, substr_count($source, "\n") + 1);
    }

    public function testVmPowNumericOperandGuardOwnsGuardBody(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmPowNumericOperandGuard.php');
        $this->assertStringContainsString('emitRuntimeStringPtrGuard', $source);
        $this->assertStringContainsString('VmValueCompare', $source);
        $this->assertGreaterThan(120, substr_count($source, "\n") + 1);
    }
}
