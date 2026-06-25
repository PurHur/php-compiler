<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamCapsJitHelper;
use PHPCompiler\ext\standard\VmStreamMeta;
use PHPUnit\Framework\TestCase;

/** stream_is_local_uri JIT routes through StreamCapsJitHelper PHP (#11413). */
final class StreamCapsRuntimeShrinkTest extends TestCase
{
    public function testStreamCapsJitDelegatesIsLocalUriToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsJit.php');
        $this->assertStringContainsString('StreamCapsRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitIsLocalUri', $source);
    }

    public function testStreamCapsRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamCapsRuntime.php');
        $this->assertStringContainsString('StreamCapsJitHelper::isLocalUriArgv', $source);
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
}
