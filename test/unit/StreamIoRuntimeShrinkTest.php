<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamIoJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * Stream I/O JIT: always NestedJIT StreamIoJitHelper — no thin stubs / libc kernel (#10326, #20943).
 */
final class StreamIoRuntimeShrinkTest extends TestCase
{
    private const BASELINE_LOC = 1010;

    public function testStreamIoJitDelegatesEmbedToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('StreamIoRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('JitStreamIoKernel', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('implementDeferredStreamIoStubs', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('emitFwrite', $source);
        $this->assertStringNotContainsString('emitFread', $source);
        $this->assertStringNotContainsString('emitFopen', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
    }

    public function testStreamIoJitShrunkAtLeastThirtyPercent(): void
    {
        $loc = substr_count((string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoJit.php'), "\n") + 1;
        $this->assertLessThanOrEqual((int) floor(self::BASELINE_LOC * 0.7), $loc, 'StreamIoJit.php LOC');
    }

    public function testStreamIoRuntimeUsesJitHelperAlwaysNoThinFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('StreamIoJitHelper::fopenArgv', $source);
        $this->assertStringContainsString('StreamIoJitHelper::freadArgv', $source);
        $this->assertStringContainsString('StreamIoJitHelper::fwriteArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('ensureRuntimeAbiDeclared', $source);
        $this->assertStringContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('JitStreamIoKernel', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('implementDeferredStreamIoStubs', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('UserScriptAotEnv', $source);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm', $source);
    }

    public function testUserScriptStreamIoKernelDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
    }

    public function testStreamIoJitHelperMemoryRoundTrip(): void
    {
        $handle = StreamIoJitHelper::fopenArgv('php://memory', 'w+b');
        $this->assertGreaterThanOrEqual(0, $handle);

        $written = StreamIoJitHelper::fwriteArgv($handle, 'hello', 5);
        $this->assertSame(5, $written);

        VmFs::fseek($handle, 0, \SEEK_SET);
        $data = StreamIoJitHelper::freadArgv($handle, 5);
        $this->assertSame('hello', $data);

        VmFs::fclose($handle);
    }

    public function testSpineBundleIncludesStreamIoPhpPathNotKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StreamIoJitHelper.php', $spine);
        $this->assertStringContainsString('StreamIoRuntime.php', $spine);
        $this->assertStringContainsString('StreamIoJit.php', $spine);
        $this->assertStringNotContainsString('JitStreamIoKernel.php', $spine);
        $this->assertStringNotContainsString('StreamIoStandaloneLlvm.php', $spine);
    }
}
