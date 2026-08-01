<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * brotli_* JIT NestedJIT via JitVmHelperLink::ensureCompiledBundle (#6814, #26668).
 *
 * Peer StringLzf #26649 / StringZstd #26596 / ObGzhandler #26331.
 */
final class BrotliJitRuntimeShrinkTest extends TestCase
{
    public function testStringBrotliCompilesBrotliJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBrotli.php');
        $this->assertStringContainsString('BrotliJitHelper::compress', $source);
        $this->assertStringContainsString('BrotliJitHelper::uncompress', $source);
        $this->assertStringContainsString('/ext/brotli/VmBrotliNative.php', $source);
        $this->assertStringContainsString('/ext/brotli/BrotliJitHelper.php', $source);
    }

    public function testStringBrotliUsesJitVmHelperLinkBundle(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBrotli.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }

    public function testBrotliCompressCallUsesJitBrotli(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/brotli/brotli_compress.php');
        $this->assertStringContainsString('JitBrotli::compress', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testBrotliUncompressCallUsesJitBrotli(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/brotli/brotli_uncompress.php');
        $this->assertStringContainsString('JitBrotli::uncompress', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }
}
