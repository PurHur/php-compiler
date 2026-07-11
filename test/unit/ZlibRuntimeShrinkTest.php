<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** zlib JIT/AOT lowering routes through ZlibJitHelper PHP, not StringZlibJit LLVM (#9879, #13347). */
final class ZlibRuntimeShrinkTest extends TestCase
{
    public function testStringZlibRoutesThroughRuntimeNotJitMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringContainsString('ZlibRuntime', $source);
        $this->assertStringNotContainsString('StringZlibJit::implement', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ZlibRuntime.php');
        $this->assertStringContainsString('ZlibJitHelper', $runtime);
        $this->assertStringContainsString('VmZlibCore', $runtime);
        $this->assertStringNotContainsString('deflateInit2_', $runtime);
        $this->assertStringNotContainsString('StringZlibJit', $runtime);

        $this->assertFileExists(__DIR__.'/../../ext/standard/ZlibJitHelper.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringZlibJit.php');
    }

    public function testZlibJitHelperDelegatesToVmZlibCore(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ZlibJitHelper.php');
        $this->assertStringContainsString('VmZlibCore::gzcompress', $source);
        $this->assertStringContainsString('VmZlibCore::gzencode', $source);
        $this->assertStringContainsString('VmZlibCore::zlib_encode', $source);
        $this->assertStringContainsString('VmZlibCore::zlib_decode', $source);
    }

    public function testStringZlibHasNoLibzDlopen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringNotContainsString('preloadLibz', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('dlopen', $source);

        $gzStream = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GzStreamRuntime.php');
        $this->assertStringNotContainsString('preloadLibz', $gzStream);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/NativeDlopen.php');
    }
}
