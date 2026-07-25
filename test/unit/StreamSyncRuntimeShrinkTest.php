<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** JitStreamSyncKernel routes libc sync + warnings through StreamSyncJitHelper via JitVmHelperLink (#9815, #19660, #23004). */
final class StreamSyncRuntimeShrinkTest extends TestCase
{
    public function testStreamSyncKernelUsesStreamSyncJitHelperNotLibcFsync(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('StreamSyncJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('StreamSyncStandaloneLlvm', $source);
        $this->assertStringNotContainsString("lookupFunction('fsync')", $source);
        $this->assertStringNotContainsString("lookupFunction('fdatasync')", $source);
        $this->assertStringNotContainsString('__compiler_trigger_error', $source);
        $this->assertStringNotContainsString('emitUnsyncableWarning', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamSyncStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamSyncJit.php');
        $this->assertLessThan(250, \substr_count($source, "\n") + 1);
    }

    public function testStreamSyncJitHelperDelegatesToVmPhpFdStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamSyncJitHelper.php');
        $this->assertStringContainsString('VmStreamSync::isSupported', $source);
        $this->assertStringContainsString('VmPhpFdStream::syncFileno', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper', $source);
    }
}
