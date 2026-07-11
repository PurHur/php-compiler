<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6791 / #13347: gz* helpers must lower without zlib_compress.c or StringZlibJit LLVM.
 *
 * @group aot-lint
 */
final class StringZlibRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesZlibCompressCAndStringZlibJit(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/zlib_compress.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/JIT/Builtin/StringZlibJit.php');

        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('zlib_compress.c', $linker);

        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringContainsString('ZlibRuntime', $runtime);
        $this->assertStringNotContainsString('StringZlibJit', $runtime);
        $this->assertStringNotContainsString('zlib_compress.c', $runtime);

        $zlibRuntime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ZlibRuntime.php');
        $this->assertStringContainsString('ZlibJitHelper', $zlibRuntime);
        $this->assertStringNotContainsString('StringZlibJit', $zlibRuntime);
        $this->assertStringNotContainsString('deflateInit2_', $zlibRuntime);
    }
}
