<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamCapsJitHelper;
use PHPCompiler\ext\standard\StreamIoJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPCompiler\ext\standard\VmStreamSupports;
use PHPUnit\Framework\TestCase;

/**
 * Stream caps NestedJIT via JitVmHelperLink (#11413, #19772, #23012).
 */
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

    public function testBuiltinStreamCapsRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertStringContainsString('JitStreamCapsKernel', $orchestrator);
        $this->assertStringContainsString('JitStreamCapsKernel::ensureLinked', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__compiler_stream_isatty', $orchestrator);
        $this->assertLessThan(55, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamCapsKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamCapsKernel', $source);
        $this->assertStringContainsString('__compiler_stream_isatty', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StreamCapsJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 2)', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamCapsKernel.php', $spine);
        $this->assertStringContainsString('StreamCapsRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamCapsKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStreamCapsStandaloneLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamCapsStandaloneLlvm.php');
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

    public function testJitStreamIsattyLazyLinksStreamCapsBeforeLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIsatty.php');
        $this->assertStringContainsString('StreamCaps::ensureLinked', $source);
        $supports = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSupports.php');
        $this->assertStringContainsString('StreamCaps::ensureLinked', $supports);
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

    public function testStreamIoJitHelperMatchesVmFsSupportsLock(): void
    {
        $handle = VmFs::tmpfile();
        $this->assertNotFalse($handle);
        $this->assertSame(
            VmFs::streamSupports($handle, VmStreamSupports::STREAM_SUPPORT_LOCK) ? 1 : 0,
            StreamIoJitHelper::supportsArgv($handle, VmStreamSupports::STREAM_SUPPORT_LOCK)
        );
        VmFs::fclose($handle);
    }
}
