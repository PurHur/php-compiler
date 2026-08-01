<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GcCollectCyclesRegistryJitHelper;
use PHPUnit\Framework\TestCase;

/** GcCollectCycles embed registry routes through GcCollectCyclesRegistryJitHelper PHP (#9541, #26333). */
final class GcCollectCyclesRegistryRuntimeShrinkTest extends TestCase
{
    public function testGcCollectCyclesRuntimeUsesRegistryJitHelperOnEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
        $this->assertStringContainsString('implementGcRegisterPhpBridge', $source);
        $this->assertStringContainsString('implementDestructAlreadyInvokedPhpBridge', $source);
        $this->assertStringContainsString('implementDestructMarkInvokedPhpBridge', $source);
        $this->assertStringContainsString('gc_register_php_entry', $source);
        $this->assertStringContainsString('usesPhpRegistry', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
    }

    public function testGcCollectCyclesRegistryJitHelperRoundtrip(): void
    {
        GcCollectCyclesRegistryJitHelper::resetForTest();
        $this->assertSame(0, GcCollectCyclesRegistryJitHelper::count());

        GcCollectCyclesRegistryJitHelper::appendObject(0x1000, 2);
        $this->assertSame(1, GcCollectCyclesRegistryJitHelper::count());
        $this->assertSame(0, GcCollectCyclesRegistryJitHelper::indexOf(0x1000));
        $this->assertSame(0x1000, GcCollectCyclesRegistryJitHelper::objectPtr(0));
        $this->assertSame(2, GcCollectCyclesRegistryJitHelper::propCount(0));
        $this->assertFalse(GcCollectCyclesRegistryJitHelper::isDestructInvoked(0));

        GcCollectCyclesRegistryJitHelper::markDestructInvoked(0);
        $this->assertTrue(GcCollectCyclesRegistryJitHelper::isDestructInvoked(0));
        $this->assertSame(1, GcCollectCyclesRegistryJitHelper::destructAlreadyInvokedByObject(0x1000));
        $this->assertSame(0, GcCollectCyclesRegistryJitHelper::destructAlreadyInvokedByObject(0x9999));

        GcCollectCyclesRegistryJitHelper::appendObject(0x2000, 1);
        GcCollectCyclesRegistryJitHelper::markDestructInvokedByObject(0x2000);
        $this->assertTrue(GcCollectCyclesRegistryJitHelper::isDestructInvoked(1));
        $this->assertSame(2, GcCollectCyclesRegistryJitHelper::count());
        GcCollectCyclesRegistryJitHelper::removeObject(0x1000);
        $this->assertSame(1, GcCollectCyclesRegistryJitHelper::count());
        $this->assertSame(0x2000, GcCollectCyclesRegistryJitHelper::objectPtr(0));

        GcCollectCyclesRegistryJitHelper::resetForTest();
    }
}
