<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Dead StringZstdJit libzstd LLVM removed — embed uses ZstdJitHelper PHP (#8869).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (#26596, peer #26568).
 */
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

    public function testStringZstdUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringZstd.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }

    public function testVmZstdNativeHasNoLibzstdFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/zstd/VmZstdNative.php');
        $this->assertStringContainsString('VmZstdCore::compress', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }
}
