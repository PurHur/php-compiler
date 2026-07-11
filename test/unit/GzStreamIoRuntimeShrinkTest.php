<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** gz* stream JIT routes through GzStreamJitHelper PHP not GzStreamIoJit LLVM (#13420). */
final class GzStreamIoRuntimeShrinkTest extends TestCase
{
    public function testGzStreamIoRoutesThroughRuntimeNotJitMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GzStreamIo.php');
        $this->assertStringContainsString('GzStreamRuntime', $source);
        $this->assertStringNotContainsString('GzStreamIoJit', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GzStreamRuntime.php');
        $this->assertStringContainsString('GzStreamJitHelper', $runtime);
        $this->assertStringContainsString('VmGzStream', $runtime);
        $this->assertStringNotContainsString('ensureLibz', $runtime);
        $this->assertStringNotContainsString('phpc_stream_is_gz', $runtime);
        $this->assertLessThan(400, \substr_count($runtime, "\n") + 1);

        $this->assertFileExists(__DIR__.'/../../ext/standard/GzStreamJitHelper.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/GzStreamIoJit.php');
    }

    public function testGzStreamJitHelperDelegatesToVmGzStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GzStreamJitHelper.php');
        $this->assertStringContainsString('VmGzStream::gzopen', $source);
        $this->assertStringContainsString('VmGzStream::gzwrite', $source);
        $this->assertStringContainsString('VmGzStream::gzread', $source);
        $this->assertStringContainsString('VmGzStream::gzclose', $source);
        $this->assertStringContainsString('VmGzStream::gzpassthru', $source);
    }
}
