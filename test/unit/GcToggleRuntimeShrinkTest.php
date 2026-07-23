<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GcToggleRuntime routes through GcToggleJitHelper PHP via JitVmHelperLink (#9577, #9687, #22644).
 */
final class GcToggleRuntimeShrinkTest extends TestCase
{
    public function testGcToggleRuntimeUsesGcToggleJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcToggleRuntime.php');
        $this->assertStringContainsString('GcToggleJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("addGlobal(\$i32, 'phpc_gc_enabled')", $source);
        $this->assertStringNotContainsString('GcToggleStandaloneLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/GcToggleStandaloneLlvm.php');
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
