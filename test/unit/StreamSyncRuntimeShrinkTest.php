<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * JitStreamSyncKernel uses libc fsync/fdatasync (not NestedJIT StreamSyncJitHelper) (#9815, #26929).
 *
 * StreamSyncJitHelper remains the VM-facing PHP SSOT for syncFileno / isSupported docs.
 */
final class StreamSyncRuntimeShrinkTest extends TestCase
{
    public function testStreamSyncKernelUsesLibcFsyncNotNestedJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('[\'fsync\', $i32, [$i32]]', $source);
        $this->assertStringContainsString('[\'fdatasync\', $i32, [$i32]]', $source);
        $this->assertStringContainsString('lookupFunction($syncName)', $source);
        $this->assertStringContainsString('__phpc_resolve_stream', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('StreamSyncJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('StreamSyncStandaloneLlvm', $source);
        $this->assertStringNotContainsString('__compiler_trigger_error', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamSyncStandaloneLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamSyncJit.php');
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testStreamSyncJitHelperDelegatesToVmPhpFdStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamSyncJitHelper.php');
        $this->assertStringContainsString('VmStreamSync::isSupported', $source);
        $this->assertStringContainsString('VmPhpFdStream::syncFileno', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper', $source);
    }
}
