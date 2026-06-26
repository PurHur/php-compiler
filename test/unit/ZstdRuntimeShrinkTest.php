<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** zstd JIT lowering — ZstdJitHelper embed; StringZstdJit gated standalone (#8869). */
final class ZstdRuntimeShrinkTest extends TestCase
{
    public function testZstdCompressCallUsesJitZstd(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/zstd/zstd_compress.php');
        $this->assertStringContainsString('JitZstd::compress', $source);
    }

    public function testStringZstdCompilesZstdJitHelperForEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZstd.php');
        $this->assertStringContainsString('ZstdJitHelper::compress', $source);
        $this->assertStringContainsString('StringZstdJit::implement', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertFileExists(__DIR__.'/../../ext/zstd/ZstdJitHelper.php');
    }

    public function testZstdJitHelperContainsCodec(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/zstd/ZstdJitHelper.php');
        $this->assertStringContainsString('function compress(', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('VmZstdCore::', $source);
    }

    public function testVmZstdNativeHasNoLibzstdFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/zstd/VmZstdNative.php');
        $this->assertStringContainsString('VmZstdCore::compress', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('libzstd.so', $source);
    }
}
