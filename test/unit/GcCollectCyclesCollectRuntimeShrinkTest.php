<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GcCollectCyclesJitHelper;
use PHPUnit\Framework\TestCase;

/** GcCollectCyclesCollectRuntime routes stats through GcCollectCyclesJitHelper PHP (#9183). */
final class GcCollectCyclesCollectRuntimeShrinkTest extends TestCase
{
    public function testGcCollectCyclesRuntimeUsesJitHelperBridge(): void
    {
        $runtimeSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesCollectRuntime', $runtimeSource);
        $this->assertStringNotContainsString('private static function implementCollectCycles(', $runtimeSource);
        $this->assertStringNotContainsString('gc_collect_entry', $runtimeSource);

        $bridgeSource = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesCollectRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesJitHelper', $bridgeSource);
        $this->assertStringContainsString('recordNativeCollect', $bridgeSource);
    }

    public function testJitGcCollectCyclesUsesRuntimeNotNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcCollectCycles.php');
        $this->assertStringContainsString('GcCollectCyclesRuntime', $source);
        $this->assertStringNotContainsString('GcCollectCyclesNative', $source);
    }

    public function testGcCollectCyclesJitHelperRecordNativeCollect(): void
    {
        GcCollectCyclesJitHelper::resetForTest();
        $this->assertSame(2, GcCollectCyclesJitHelper::recordNativeCollect(2));
        $this->assertSame(1, GcCollectCyclesJitHelper::runs());
        $this->assertSame(2, GcCollectCyclesJitHelper::totalCollected());
        $this->assertFalse(GcCollectCyclesJitHelper::isRunning());
        $this->assertFalse(GcCollectCyclesJitHelper::isProtected());
    }
}
