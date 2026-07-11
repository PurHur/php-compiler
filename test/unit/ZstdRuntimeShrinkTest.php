<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Dead StringZstdJit libzstd LLVM removed — embed uses ZstdJitHelper PHP (#8869). */
final class ZstdRuntimeShrinkTest extends TestCase
{
    public function testStringZstdJitFileRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringZstdJit.php');
    }

    public function testStringZstdCompilesZstdJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZstd.php');
        $this->assertStringContainsString('ZstdJitHelper::compress', $source);
        $this->assertStringNotContainsString('StringZstdJit', $source);
        $this->assertStringNotContainsString('libzstd', $source);
    }

    public function testVmZstdNativeHasNoLibzstdFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/zstd/VmZstdNative.php');
        $this->assertStringContainsString('VmZstdCore::compress', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }
}
