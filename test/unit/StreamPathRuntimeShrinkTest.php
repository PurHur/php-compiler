<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\ext\standard\StreamPathJitHelper;
use PHPCompiler\ext\standard\VmFs;

/** StreamPathRuntime must route through StreamPathJitHelper PHP, not LLVM path tables (#9480). */
final class StreamPathRuntimeShrinkTest extends TestCase
{
    public function testStreamPathRuntimeUsesJitHelperNotLlvmTableLookup(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamPathRuntime.php');
        $this->assertStringContainsString('StreamPathJitHelper', $source);
        $this->assertStringNotContainsString('loadTableSlot', $source);
        $this->assertStringNotContainsString("GLOBAL_PATHS = 'phpc_stream_paths'", $source);
        $this->assertStringNotContainsString('implementPathLookup', $source);
        $this->assertStringContainsString('emitRegisterPath', $source);
        $this->assertStringContainsString('emitClearPath', $source);
    }

    public function testStreamPathJitHelperDelegatesToVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamPathJitHelper.php');
        $this->assertStringContainsString('VmFs::registerStreamPath', $source);
        $this->assertStringContainsString('VmFs::handleUri', $source);
    }

    public function testStreamPathJitHelperRoundTrip(): void
    {
        $handle = VmFs::allocateStreamHandleId();
        $this->assertNull(StreamPathJitHelper::pathForHandle($handle));
        StreamPathJitHelper::register($handle, '/tmp/phpc_stream_path_probe.txt');
        $this->assertSame('/tmp/phpc_stream_path_probe.txt', StreamPathJitHelper::pathForHandle($handle));
        StreamPathJitHelper::clear($handle);
        $this->assertNull(StreamPathJitHelper::pathForHandle($handle));
    }
}
