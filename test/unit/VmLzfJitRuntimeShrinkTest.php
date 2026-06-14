<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** lzf_* JIT lowering via StringLzfJit — no VM-only throw in call() (#6384 phase 2). */
final class VmLzfJitRuntimeShrinkTest extends TestCase
{
    public function testLzfCompressCallUsesJitLzf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/lzf/lzf_compress.php');
        $this->assertStringContainsString('JitLzf::compress', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testLzfDecompressCallUsesJitLzf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/lzf/lzf_decompress.php');
        $this->assertStringContainsString('JitLzf::decompress', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testStringLzfJitDeclaresRuntimeHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLzfJit.php');
        $this->assertStringContainsString('__compiler_lzf_compress', $source);
        $this->assertStringContainsString('__compiler_lzf_decompress', $source);
        $this->assertStringContainsString('lzf_compress', $source);
        $this->assertStringContainsString('lzf_decompress', $source);
    }
}
