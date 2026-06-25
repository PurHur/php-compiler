<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Bound-method FCC JIT routes through VmBoundMethodCallable PHP SSOT (#10185). */
final class BoundMethodCallableHelperRuntimeShrinkTest extends TestCase
{
    public function testBoundMethodCallableHelperIsThinTrampoline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BoundMethodCallableHelper.php');
        $this->assertStringContainsString('VmBoundMethodCallable', $source);
        $this->assertStringNotContainsString('resolveObjectOperandRoot', $source);
        $this->assertStringNotContainsString('classNameFromReceiverSlot', $source);
        $this->assertStringNotContainsString('TYPE_ADD_ARRAY_ELEMENT', $source);
        $this->assertLessThanOrEqual(60, substr_count($source, "\n") + 1);
    }

    public function testVmBoundMethodCallableOwnsFccResolution(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmBoundMethodCallable.php');
        $this->assertStringContainsString('resolveMethodLcFromCalleeSlot', $source);
        $this->assertStringContainsString('resolveBoundMethodReceiverOperand', $source);
        $this->assertStringContainsString('resolveInvokableObjectReceiverOperand', $source);
        $this->assertStringContainsString('classNameFromObjectSlot', $source);
        $this->assertGreaterThan(300, substr_count($source, "\n") + 1);
    }

    public function testVmFromCallableUsesVmBoundMethodCallable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/VmFromCallable.php');
        $this->assertStringContainsString('VmBoundMethodCallable::', $source);
        $this->assertStringNotContainsString('BoundMethodCallableHelper', $source);
    }
}
