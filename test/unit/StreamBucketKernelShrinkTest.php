<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamBucketJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * stream_bucket_* ABI bridges quarantined in ext/standard (#9380, #19712).
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

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamBucketKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamBucketKernel', $source);
        $this->assertStringContainsString('__compiler_stream_bucket_register', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringContainsString('StreamBucketJitHelper', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('isStandaloneInitPhase', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('GLOBAL_BUCKET_ACTIVE', $source);
        $this->assertStringNotContainsString('emitBucketRegister', $source);
        $this->assertLessThan(400, \substr_count($source, "\n") + 1);
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
