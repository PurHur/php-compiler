<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamReadJitHelper;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPUnit\Framework\TestCase;

/**
 * StreamReadRuntime: always NestedJIT StreamReadJitHelper — no thin deferred stubs (#9393, #20982).
 */
final class StreamReadRuntimeShrinkTest extends TestCase
{
    public function testStreamReadRuntimeUsesJitHelperAlwaysNoThinFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadRuntime.php');
        $this->assertStringContainsString('StreamReadJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        // Thin AOT still NestedJITs the helper, then forceLibc* replaces FILE*-table ABIs (#27663, #27437).
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('forceLibcStreamPositionAbis', $source);
        $this->assertStringContainsString('implementStreamGetContentsForce', $source);
        $this->assertStringNotContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
        $this->assertStringNotContainsString('shouldDeferInventoryEmitStubs', $source);
        $this->assertStringNotContainsString('implementDeferredStubs', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StreamReadStandaloneLlvm', $source);
        $this->assertStringNotContainsString('__phpc_resolve_stream', $source);
        $this->assertLessThan(230, \substr_count($source, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamReadStandaloneLlvm.php');
    }

    public function testStreamReadJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamReadJit.php');
        $this->assertStringContainsString('StreamReadRuntime', $source);
        $this->assertStringNotContainsString('StreamReadStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitFlock', $source);
        $this->assertStringNotContainsString('emitFgets', $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testStreamReadJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamReadJitHelper.php');
        $this->assertStringContainsString('VmFs::flock', $source);
        $this->assertStringContainsString('VmFs::fgets', $source);
        $this->assertStringContainsString('VmFs::streamGetContents', $source);
    }

    public function testStreamReadJitHelperMemoryRoundTrip(): void
    {
        $handle = VmPhpMemoryStream::open('php://memory', 'w+b');
        $this->assertNotFalse($handle);
        VmPhpMemoryStream::write((int) $handle, "hello\nworld");
        VmPhpMemoryStream::seek((int) $handle, 0, \SEEK_SET);

        $line = StreamReadJitHelper::fgetsArgv((int) $handle, 8192);
        $this->assertSame("hello\n", $line);

        $this->assertSame(0, StreamReadJitHelper::fseekArgv((int) $handle, 0, \SEEK_SET));
        $all = StreamReadJitHelper::streamGetContentsArgv((int) $handle, -1, -1);
        $this->assertSame("hello\nworld", $all);
    }
}
