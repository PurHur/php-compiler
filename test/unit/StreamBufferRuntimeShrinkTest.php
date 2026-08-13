<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamBufferJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * Stream buffer NestedJIT via JitVmHelperLink::ensureCompiled (#14462, #19788, #22979).
 */
final class StreamBufferRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 441;

    public function testStreamBufferJitDelegatesToRuntimeOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBufferJit.php');
        $this->assertStringContainsString('StreamBufferRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('setvbuf', $source);
        $this->assertStringNotContainsString('emitSetChunkSize', $source);
        $this->assertStringNotContainsString('emitSetBuffer', $source);
    }

    public function testStreamBufferJitShrunkAtLeastSeventyPercent(): void
    {
        $loc = \substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBufferJit.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.3), $loc, 'StreamBufferJit.php LOC');
    }

    public function testBuiltinStreamBufferRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamBufferKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StreamBufferRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBufferRuntime.php');
        $this->assertStringContainsString('JitStreamBufferKernel', $orchestrator);
        $this->assertStringContainsString('JitStreamBufferKernel::ensureLinked', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__compiler_stream_set_chunk_size', $orchestrator);
        $this->assertLessThan(55, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamBufferKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamBufferKernel', $source);
        $this->assertStringContainsString('__compiler_stream_set_chunk_size', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StreamBufferJitHelper', $source);
        $this->assertStringContainsString('implementThinStandaloneBuffers', $source);
        $this->assertStringContainsString('isDeferStub', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(340, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamBufferKernel.php', $spine);
        $this->assertStringContainsString('StreamBufferRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamBufferKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StreamBufferRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStreamBufferJitHelperMemoryRoundTrip(): void
    {
        $handle = VmFs::fopen('php://memory', 'w+b');
        $this->assertGreaterThanOrEqual(0, $handle);

        $previous = StreamBufferJitHelper::setChunkSizeArgv($handle, 4096);
        $this->assertGreaterThanOrEqual(0, $previous);

        $writePrev = StreamBufferJitHelper::setWriteBufferArgv($handle, 0);
        $this->assertSame(-1, $writePrev);

        $readPrev = StreamBufferJitHelper::setReadBufferArgv($handle, 8192);
        $this->assertSame(0, $readPrev);

        VmFs::fclose($handle);

        $path = tempnam(sys_get_temp_dir(), 'phpc_sb_');
        $this->assertNotFalse($path);
        $fileHandle = VmFs::fopen($path, 'w+b');
        $this->assertGreaterThanOrEqual(0, $fileHandle);
        // php-src: READ_TIMEOUT unsupported on plainfile → 0 (#25924)
        $this->assertSame(0, StreamBufferJitHelper::setTimeoutArgv($fileHandle, 1, 0));
        VmFs::fclose($fileHandle);
        @unlink($path);
    }
}
