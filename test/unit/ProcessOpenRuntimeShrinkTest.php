<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ProcessOpenJitHelper;
use PHPCompiler\ext\standard\ProcessSlotJitHelper;
use PHPUnit\Framework\TestCase;

/** ProcessOpenRuntime embed routes proc_close/status through ProcessOpenJitHelper PHP (#9408). */
final class ProcessOpenRuntimeShrinkTest extends TestCase
{
    public function testProcessOpenRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenRuntime.php');
        $this->assertStringContainsString('ProcessOpenStandaloneLlvm::implement', $runtime);
        $this->assertStringContainsString('ProcessOpenEmbedBridge::implement', $runtime);
        $this->assertStringNotContainsString('emitProcClose', $runtime);
        $this->assertLessThan(35, \substr_count($runtime, "\n") + 1);
    }

    public function testProcessOpenJitIsBackCompatRouter(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenJit.php');
        $this->assertStringContainsString('ProcessOpenRuntime::implement', $jit);
        $this->assertStringNotContainsString('emitProcOpen', $jit);
        $this->assertLessThan(35, \substr_count($jit, "\n") + 1);
    }

    public function testEmbedBridgeUsesPhpHelperNotLifecycleEmitters(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenEmbedBridge.php');
        $this->assertStringContainsString('ProcessOpenJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('emitProcClose', $bridge);
        $this->assertStringNotContainsString('emitProcGetStatus', $bridge);
        $this->assertStringNotContainsString('GLOBAL_PIDS', $bridge);
    }

    public function testProcessOpenJitHelperSlotRoundTrip(): void
    {
        ProcessOpenJitHelper::resetForTest();
        ProcessSlotJitHelper::register(0, 99999, 'echo test');
        $handle = ProcessOpenJitHelper::PROCESS_HANDLE_BASE;
        $this->assertSame(1, ProcessOpenJitHelper::isProcessResourceArgv($handle));
        ProcessSlotJitHelper::resetForTest();
        $this->assertSame(0, ProcessOpenJitHelper::isProcessResourceArgv($handle));
    }
}
