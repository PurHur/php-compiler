<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * JitStreamSyncKernel emits libc fsync/fdatasync (NestedJIT SEGV under thin AOT, #26929).
 */
final class StreamSyncRuntimeShrinkTest extends TestCase
{
    public function testStreamSyncKernelUsesLibcFsyncNotNestedJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString("lookupFunction(\$syncName)", $source);
        $this->assertStringContainsString("'fsync'", $source);
        $this->assertStringContainsString("'fdatasync'", $source);
        $this->assertStringContainsString('__compiler_trigger_error', $source);
        $this->assertStringContainsString('emitUnsyncableWarning', $source);
        $this->assertStringContainsString('tryGetInsertBlock', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('StreamSyncStandaloneLlvm', $source);
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
        $this->assertStringContainsString('JitStreamSyncKernel', $source);
    }
}
