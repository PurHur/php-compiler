<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamBucketJitHelper;
use PHPUnit\Framework\TestCase;

/** StreamBucket JIT routes through StreamBucketJitHelper PHP not slot-table LLVM (#9380). */
final class StreamBucketRuntimeShrinkTest extends TestCase
{
    public function testStreamBucketRuntimeUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamBucketRuntime.php');
        $this->assertStringContainsString('StreamBucketJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('GLOBAL_BUCKET_ACTIVE', $source);
        $this->assertStringNotContainsString('emitBucketRegister', $source);
        $this->assertStringNotContainsString('loadTableU8', $source);
        $this->assertLessThan(400, \substr_count($source, "\n") + 1);
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
