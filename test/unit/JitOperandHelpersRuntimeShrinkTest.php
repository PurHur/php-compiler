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

    public function testJitNumericDivisionGuardIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitNumericDivisionGuard.php');
        $this->assertStringContainsString('VmNumericDivisionGuard', $source);
        $this->assertStringNotContainsString('emitCatchableClassError', $source);
        $this->assertLessThanOrEqual(40, substr_count($source, "\n") + 1);
    }

    public function testVmNumericDivisionGuardOwnsDivisionGuards(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmNumericDivisionGuard.php');
        $this->assertStringContainsString('emitCatchableClassError', $source);
        $this->assertStringContainsString('DivisionByZeroError', $source);
        $this->assertGreaterThan(55, substr_count($source, "\n") + 1);
    }

    public function testJitEnumNumericOperandGuardIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitEnumNumericOperandGuard.php');
        $this->assertStringContainsString('VmEnumNumericOperandGuard', $source);
        $this->assertStringNotContainsString('emitObjectEnumReject', $source);
        $this->assertLessThanOrEqual(40, substr_count($source, "\n") + 1);
    }

    public function testVmEnumNumericOperandGuardOwnsEnumReject(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmEnumNumericOperandGuard.php');
        $this->assertStringContainsString('emitObjectEnumReject', $source);
        $this->assertStringContainsString('emitValueBoxEnumReject', $source);
        $this->assertGreaterThan(200, substr_count($source, "\n") + 1);
    }

    public function testJitUnaryPlusIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitUnaryPlus.php');
        $this->assertStringContainsString('VmUnaryPlus', $source);
        $this->assertStringNotContainsString('strtol', $source);
        $this->assertLessThanOrEqual(25, substr_count($source, "\n") + 1);
    }

    public function testVmUnaryPlusOwnsStringLowering(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmUnaryPlus.php');
        $this->assertStringContainsString('strtol', $source);
        $this->assertStringContainsString('emitNonNumericWarning', $source);
        $this->assertGreaterThan(150, substr_count($source, "\n") + 1);
    }

    public function testJitUnaryMinusIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitUnaryMinus.php');
        $this->assertStringContainsString('VmUnaryMinus', $source);
        $this->assertStringNotContainsString('fNegate', $source);
        $this->assertLessThanOrEqual(25, substr_count($source, "\n") + 1);
    }

    public function testVmUnaryMinusOwnsNegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmUnaryMinus.php');
        $this->assertStringContainsString('fNegate', $source);
        $this->assertStringContainsString('VmUnaryPlus', $source);
        $this->assertGreaterThan(50, substr_count($source, "\n") + 1);
    }
}
