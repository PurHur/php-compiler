<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ProcessOpenJitHelper;
use PHPCompiler\ext\standard\ProcessSlotJitHelper;
use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPCompiler\ext\standard\VmProcessProcOpenNative;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/** ProcessOpenRuntime routes standalone + embed through ProcessOpenJitHelper PHP (#9408, #12958). */
final class ProcessOpenRuntimeShrinkTest extends TestCase
{
    public function testProcessOpenRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenRuntime.php');
        $this->assertStringContainsString('ProcessOpenEmbedBridge::implement', $runtime);
        $this->assertStringNotContainsString('ProcessOpenStandaloneLlvm', $runtime);
        $this->assertStringNotContainsString('emitProcClose', $runtime);
        $this->assertLessThan(35, \substr_count($runtime, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ProcessOpenStandaloneLlvm.php');
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
        $this->assertStringContainsString('procOpenArgv', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('ProcessOpenStandaloneLlvm', $bridge);
        $this->assertStringNotContainsString('emitProcClose', $bridge);
        $this->assertStringNotContainsString('emitProcGetStatus', $bridge);
        $this->assertStringNotContainsString('GLOBAL_PIDS', $bridge);
    }

    public function testProcessOpenJitHelperDelegatesToVmSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcessOpenJitHelper.php');
        $this->assertStringContainsString('VmProcessProcOpenNative::open', $source);
        $this->assertStringContainsString('VmProcessProcOpenNative::close', $source);
        $this->assertStringContainsString('VmProcessProcOpenNative::getStatus', $source);
        $this->assertStringContainsString('VmProcessProcOpenNative::terminate', $source);
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

    public function testProcOpenArgvEchoWhenFfiAvailable(): void
    {
        if (!VmProcessProcOpenNative::available() || !VmPhpFdStream::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        ProcessOpenJitHelper::resetForTest();
        $pipes = new HashTable();
        $handle = ProcessOpenJitHelper::procOpenArgv('echo ok', $pipes);
        $this->assertGreaterThan(0, $handle);
        $this->assertSame(1, ProcessOpenJitHelper::isProcessResourceArgv($handle));

        $stdoutVar = $pipes->findIndex(1);
        $this->assertNotNull($stdoutVar);
        $streamHandle = $stdoutVar->toInt();
        $out = \PHPCompiler\ext\standard\VmFs::fread($streamHandle, 8192);
        \PHPCompiler\ext\standard\VmFs::fclose($streamHandle);
        $this->assertSame('ok', trim((string) $out));

        $code = ProcessOpenJitHelper::procCloseArgv($handle);
        $this->assertSame(0, $code);
    }
}
