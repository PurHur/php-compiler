<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GcCollectCyclesRegistryJitHelper;
use PHPUnit\Framework\TestCase;

/** GcCollectCycles embed registry routes through GcCollectCyclesRegistryJitHelper PHP (#9541). */
final class GcCollectCyclesRegistryRuntimeShrinkTest extends TestCase
{
    public function testGcCollectCyclesRuntimeUsesRegistryJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
        $this->assertStringContainsString('usesPhpRegistry', $source);
        $this->assertStringContainsString('implementGcRegisterPhpBridge', $source);
    }

    public function testEmbedSkipsLlvmRegistryGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('standaloneRegistry', $source);
        $this->assertStringContainsString('$standaloneRegistry && null === $context->module->getNamedGlobal(self::G_OBJECTS)', $source);
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

        GcCollectCyclesRegistryJitHelper::appendObject(0x2000, 1);
        $this->assertSame(2, GcCollectCyclesRegistryJitHelper::count());
        GcCollectCyclesRegistryJitHelper::removeObject(0x1000);
        $this->assertSame(1, GcCollectCyclesRegistryJitHelper::count());
        $this->assertSame(0x2000, GcCollectCyclesRegistryJitHelper::objectPtr(0));

        GcCollectCyclesRegistryJitHelper::resetForTest();
    }
}
