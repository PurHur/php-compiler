<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #6791: gz* LLVM helpers must lower without zlib_compress.c.
 *
 * @group aot-lint
 */
final class StringZlibRuntimeStandaloneTest extends TestCase
{
    public function testRuntimeShrinkRemovesZlibCompressC(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../../lib/AOT/runtime/zlib_compress.c');
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringZlibJit.php');
        $this->assertStringContainsString('__compiler_gzcompress', $jit);
        $this->assertStringContainsString('StringZlibJit', $jit);
        $this->assertStringContainsString('deflateInit2_', $jit);
        $this->assertStringContainsString('inflateInit2_', $jit);
        $this->assertStringNotContainsString("'deflateInit2'", $jit);
        $this->assertStringNotContainsString("'inflateInit2'", $jit);
        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('zlib_compress.c', $linker);
        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringZlib.php');
        $this->assertStringContainsString('ZlibRuntime', $runtime);
        $this->assertStringNotContainsString('zlib_compress.c', $runtime);
        $zlibRuntime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/ZlibRuntime.php');
        $this->assertStringContainsString('StringZlibJit', $zlibRuntime);
    }

}
