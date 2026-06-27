<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamCapsJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPCompiler\ext\standard\VmStreamSupports;
use PHPUnit\Framework\TestCase;

/** stream caps JIT routes through StreamCapsJitHelper PHP (#11413). */
final class StreamCapsRuntimeShrinkTest extends TestCase
{
    public function testStreamCapsJitDelegatesToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsJit.php');
        $this->assertStringContainsString('StreamCapsRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('StreamCapsStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitIsatty', $source);
        $this->assertStringNotContainsString('emitIsLocal', $source);
        $this->assertStringNotContainsString('emitSupports', $source);
    }

    public function testStreamCapsStandaloneLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamCapsStandaloneLlvm.php');
    }

    public function testStreamCapsRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertStringContainsString('StreamCapsJitHelper::isLocalUriArgv', $source);
        $this->assertStringContainsString('StreamCapsJitHelper::isattyArgv', $source);
        $this->assertStringContainsString('StreamCapsJitHelper::isLocalArgv', $source);
        $this->assertStringContainsString('StreamCapsJitHelper::supportsArgv', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
    }

    public function testStreamCapsJitHelperMatchesVmStreamMeta(): void
    {
        $this->assertSame(1, StreamCapsJitHelper::isLocalUriArgv('/tmp/foo'));
        $this->assertSame(1, StreamCapsJitHelper::isLocalUriArgv('php://memory'));
        $this->assertSame(0, StreamCapsJitHelper::isLocalUriArgv('http://example.com'));
        $this->assertSame(
            VmStreamMeta::isLocalUri('https://example.com') ? 1 : 0,
            StreamCapsJitHelper::isLocalUriArgv('https://example.com')
        );
    }

    public function testStreamCapsJitHelperMatchesVmFsIsLocal(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame(
            VmFs::streamIsLocal($handle) ? 1 : 0,
            StreamCapsJitHelper::isLocalArgv($handle)
        );
        VmFs::fclose($handle);
    }

    public function testStreamCapsJitHelperMatchesVmFsSupports(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        $this->assertSame(
            VmFs::streamSupports($handle, VmStreamSupports::STREAM_SUPPORT_LOCK) ? 1 : 0,
            StreamCapsJitHelper::supportsArgv($handle, VmStreamSupports::STREAM_SUPPORT_LOCK)
        );
        $this->assertSame(
            VmFs::streamSupports($handle, VmStreamSupports::STREAM_SUPPORT_SEEK) ? 1 : 0,
            StreamCapsJitHelper::supportsArgv($handle, VmStreamSupports::STREAM_SUPPORT_SEEK)
        );
        VmFs::fclose($handle);
    }
}
