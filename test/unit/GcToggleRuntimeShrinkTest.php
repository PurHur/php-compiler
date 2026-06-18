<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** GcToggleRuntime must route through GcToggleJitHelper PHP, not LLVM phpc_gc_enabled global (#9577). */
final class GcToggleRuntimeShrinkTest extends TestCase
{
    public function testGcToggleRuntimeUsesGcToggleJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcToggleRuntime.php');
        $this->assertStringContainsString('GcToggleJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$i32, 'phpc_gc_enabled')", $source);
    }

    public function testGcCollectCyclesRuntimeDroppedEnabledGlobal(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcToggleRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString("G_ENABLED = 'phpc_gc_enabled'", $source);
        $this->assertStringNotContainsString('implementGcIsEnabled', $source);
    }

    public function testJitGcToggleUsesGcToggleRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcToggle.php');
        $this->assertStringContainsString('GcToggleRuntime', $source);
        $this->assertStringNotContainsString('GcCollectCyclesRuntime', $source);
        $this->assertStringNotContainsString('GcCollectCyclesNative', $source);
    }
}
