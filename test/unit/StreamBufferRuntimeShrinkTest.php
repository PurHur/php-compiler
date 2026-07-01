<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamBufferJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** Stream buffer JIT routes through StreamBufferJitHelper PHP not inline LLVM (#14462). */
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

    public function testStreamBufferRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBufferRuntime.php');
        $this->assertStringContainsString('StreamBufferJitHelper::setChunkSizeArgv', $source);
        $this->assertStringContainsString('StreamBufferJitHelper::setWriteBufferArgv', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('setvbuf', $source);
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
        $this->assertSame(1, StreamBufferJitHelper::setTimeoutArgv($fileHandle, 1, 0));
        VmFs::fclose($fileHandle);
        @unlink($path);
    }
}
