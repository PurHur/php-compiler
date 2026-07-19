<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamBucketJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * stream_bucket_* ABI bridges — always NestedJIT StreamBucketJitHelper (#9380, #20998).
 */
final class StreamBucketKernelShrinkTest extends TestCase
{
    public function testBuiltinStreamBucketRuntimeMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamBucketRuntime.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamBucketKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBucket.php');
        $this->assertStringContainsString('JitStreamBucketKernel', $runtime);
        $this->assertStringNotContainsString('StreamBucketRuntime', $runtime);
    }

    public function testKernelAlwaysNestedJitNoThinStubFork(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamBucketKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamBucketKernel', $source);
        $this->assertStringContainsString('__compiler_stream_bucket_register', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('StreamBucketJitHelper', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
        $this->assertStringNotContainsString('shouldDeferInventoryEmitStubs', $source);
        $this->assertStringNotContainsString('implementDeferredResourceProbeStubs', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('GLOBAL_BUCKET_ACTIVE', $source);
        $this->assertStringNotContainsString('emitBucketRegister', $source);
        $this->assertLessThan(360, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelNotBuiltinRuntime(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamBucketKernel.php', $spine);
        $this->assertStringNotContainsString('StreamBucketRuntime.php', $spine);
        $this->assertStringContainsString('StreamBucket.php', $spine);
    }

    public function testStreamBucketJitHelperRegistryRoundtrip(): void
    {
        StreamBucketJitHelper::resetForTest();

        $handle = StreamBucketJitHelper::registerBucket('payload');
        $this->assertSame(StreamBucketJitHelper::BUCKET_HANDLE_BASE, $handle);
        $this->assertSame(1, StreamBucketJitHelper::isBucketResource($handle));
        $this->assertSame('payload', StreamBucketJitHelper::bucketData($handle));

        $brigade = StreamBucketJitHelper::brigadeAlloc();
        $this->assertSame(StreamBucketJitHelper::BRIGADE_HANDLE_BASE, $brigade);
        $this->assertSame(1, StreamBucketJitHelper::isBrigadeResource($brigade));
        $this->assertSame(1, StreamBucketJitHelper::brigadePush($brigade, $handle));
        $this->assertSame($handle, StreamBucketJitHelper::brigadePop($brigade));
        $this->assertSame(-1, StreamBucketJitHelper::brigadePop($brigade));
    }
}
