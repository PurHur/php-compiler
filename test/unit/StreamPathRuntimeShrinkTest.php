<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamPathJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * StreamPathRuntime routes through StreamPathJitHelper PHP via
 * JitVmHelperLink::ensureCompiled (#9480 / #25139 / peer #25092).
 */
final class StreamPathRuntimeShrinkTest extends TestCase
{
    public function testStreamPathRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamPathRuntime.php');
        $this->assertStringContainsString('StreamPathJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('emitRegisterPath', $source);
        $this->assertStringContainsString('emitClearPath', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('loadTableSlot', $source);
        $this->assertStringNotContainsString("GLOBAL_PATHS = 'phpc_stream_paths'", $source);
        $this->assertStringNotContainsString('implementPathLookup', $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }

    public function testStreamPathJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamPathJitHelper.php');
        $this->assertStringContainsString('VmFs::registerStreamPath', $source);
        $this->assertStringContainsString('VmFs::clearStreamPath', $source);
        $this->assertStringContainsString('VmFs::handleUri', $source);
    }

    public function testStreamPathJitHelperRoundTrip(): void
    {
        $handle = VmFs::allocateStreamHandleId();
        $this->assertNull(StreamPathJitHelper::pathForHandle($handle));
        StreamPathJitHelper::register($handle, '/tmp/phpc_stream_path_probe.txt');
        $this->assertSame('/tmp/phpc_stream_path_probe.txt', StreamPathJitHelper::pathForHandle($handle));
        $this->assertSame('/tmp/phpc_stream_path_probe.txt', VmFs::handleUri($handle));
        StreamPathJitHelper::clear($handle);
        $this->assertNull(StreamPathJitHelper::pathForHandle($handle));
        $this->assertSame('', VmFs::handleUri($handle));
        $this->assertNull(StreamPathJitHelper::pathForHandle(0));
    }
}
