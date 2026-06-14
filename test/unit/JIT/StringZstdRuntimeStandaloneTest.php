<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/**
 * Issue #8564: zstd_* LLVM helpers must lower without C runtime TUs.
 *
 * @group aot-lint
 */
final class StringZstdRuntimeStandaloneTest extends TestCase
{
    public function testStringZstdJitDefinesRuntimeSymbols(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringZstdJit.php');
        $this->assertStringContainsString('__compiler_zstd_compress', $jit);
        $this->assertStringContainsString('__compiler_zstd_decompress', $jit);
        $this->assertStringContainsString('ZSTD_compress', $jit);

        $runtime = (string) file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringZstd.php');
        $this->assertStringContainsString('StringZstdJit', $runtime);

        $linker = (string) file_get_contents(__DIR__.'/../../../lib/AOT/Linker.php');
        $this->assertStringContainsString('libzstd.so.1', $linker);

        $compress = (string) file_get_contents(__DIR__.'/../../../ext/zstd/zstd_compress.php');
        $this->assertStringContainsString('JitZstd::compress', $compress);
        $this->assertStringNotContainsString('not implemented for JIT', $compress);
    }
}
