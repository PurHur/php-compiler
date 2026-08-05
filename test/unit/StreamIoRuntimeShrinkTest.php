<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamIoJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * Stream I/O: thin AOT via JitStreamIoKernel libc; embed NestedJIT (#10326, #20943, #26929).
 */
final class StreamIoRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1010;

    public function testStreamIoJitRoutesThinAotToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('JitStreamIoKernel', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('StreamIoRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitFwrite', $source);
        $this->assertStringNotContainsString('emitFopen', $source);
    }

    public function testStreamIoJitShrunkAtLeastThirtyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.7), $loc, 'StreamIoJit.php LOC');
    }

    public function testStreamIoRuntimeUserScriptUsesKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('JitStreamIoKernel::implementForUserScriptLowering', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('StreamIoJitHelper::fopenArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
    }

    public function testUserScriptStreamIoKernelPresent(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString('implementForUserScriptLowering', $kernel);
        $this->assertStringContainsString('__compiler_fopen', $kernel);
        $this->assertStringContainsString('implementStreamGetContentsForce', $kernel);
        $this->assertStringContainsString('sgc_entry', $kernel);
    }

    public function testStreamIoJitHelperMemoryAllocatesLiveHandle(): void
    {
        // php://memory uses JitOpenStreamHandles (NestedJIT cannot VmFs — #23777).
        // fwrite/fread of that table is StreamRead/JitMemoryStreamHelper territory (#25299).
        $handle = StreamIoJitHelper::fopenArgv('php://memory', 'w+b');
        $this->assertGreaterThan(0, $handle);
        $this->assertTrue(\PHPCompiler\ext\standard\JitOpenStreamHandles::isOpen($handle));
        \PHPCompiler\ext\standard\JitOpenStreamHandles::release($handle);
    }

    public function testSpineBundleIncludesStreamIoKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamIoKernel.php', $spine);
        $this->assertStringContainsString('StreamIoJitHelper.php', $spine);
        $this->assertStringContainsString('StreamIoRuntime.php', $spine);
        $this->assertStringContainsString('StreamIoJit.php', $spine);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm.php', $spine);
    }
}
