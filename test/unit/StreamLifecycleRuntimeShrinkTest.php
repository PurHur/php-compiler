<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamLifecycleJitHelper;
use PHPCompiler\ext\standard\StreamLibcHandleJitHelper;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPUnit\Framework\TestCase;

/**
 * Stream lifecycle NestedJIT ABI bridges — always StreamLifecycleJitHelper (#9442, #20966).
 */
final class StreamLifecycleRuntimeShrinkTest extends TestCase
{
    public function testStreamLifecycleJitIsThinDispatcher(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamLifecycleJit.php');
        $this->assertStringContainsString('StreamLifecycleRuntime', $source);
        $this->assertStringNotContainsString('StreamLifecycleStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitIsResource', $source);
        $this->assertStringNotContainsString('emitFclose', $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testBuiltinStreamLifecycleRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StreamLifecycleRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamLifecycleRuntime.php');
        $this->assertStringContainsString('JitStreamLifecycleKernel', $orchestrator);
        $this->assertStringContainsString('JitStreamLifecycleKernel::ensureLinked', $orchestrator);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $orchestrator);
        $this->assertStringNotContainsString('implementDeferredStubs', $orchestrator);
        $this->assertStringNotContainsString('shouldDeferInventoryEmitStubs', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__compiler_is_resource', $orchestrator);
        $this->assertLessThan(45, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelAlwaysNestedJitNoThinStubFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamLifecycleKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamLifecycleKernel', $source);
        $this->assertStringContainsString('__compiler_is_resource', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('StreamLifecycleJitHelper', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('StreamGlobalsJit::implementThinIsResource', $source);
        $this->assertStringContainsString('JitMemoryStreamHelper.php', $source);
        $this->assertStringNotContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('implementDeferredStubs', $source);
        $this->assertStringNotContainsString('shouldDeferInventoryEmitStubs', $source);
        $this->assertStringNotContainsString('implementStandalone', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamLifecycleKernel.php', $spine);
        $this->assertStringContainsString('StreamLifecycleRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitStreamLifecycleKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StreamLifecycleRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }

    public function testStreamLifecycleJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamLifecycleJitHelper.php');
        $this->assertStringContainsString('VmFs::fclose', $source);
        $this->assertStringContainsString('VmFs::feof', $source);
        $this->assertStringContainsString('VmDir::isValidHandle', $source);
        $this->assertStringContainsString('StreamLibcHandleJitHelper', $source);
    }

    public function testStreamLifecycleJitHelperVmMemoryRoundTrip(): void
    {
        $handle = VmPhpMemoryStream::open('php://memory', 'w+b');
        $this->assertNotFalse($handle);
        $this->assertSame(1, StreamLifecycleJitHelper::isResourceArgv((int) $handle));
        $this->assertSame(0, StreamLifecycleJitHelper::feofArgv((int) $handle));
        $this->assertSame(1, StreamLifecycleJitHelper::fcloseArgv((int) $handle));
        $this->assertSame(0, StreamLifecycleJitHelper::isResourceArgv((int) $handle));
    }

    public function testStreamLibcHandleJitHelperRegisterRoundTrip(): void
    {
        StreamLibcHandleJitHelper::resetForTest();
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0x1234);
        $this->assertSame(0x1234, StreamLibcHandleJitHelper::resolvePtr(7));
        StreamLibcHandleJitHelper::registerFromPtr(7, 0);
        $this->assertSame(0, StreamLibcHandleJitHelper::resolvePtr(7));
    }

    public function testStreamLifecycleStandaloneLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamLifecycleStandaloneLlvm.php');
    }

    public function testStreamIoStandaloneLlvmDeletedForUserScriptAot(): void
    {
        // #20943 NestedJIT-only path regressed thin AOT; #26929 restored libc kernel.
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamIoStandaloneLlvm.php');
        $io = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('JitStreamIoKernel::implementForUserScriptLowering', $io);
        $this->assertStringContainsString('isThinStandaloneAotMain', $io);
    }
}
