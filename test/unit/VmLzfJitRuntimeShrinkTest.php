<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * lzf_* JIT lowering via LzfJitHelper — no native liblzf (#6384, #8805).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (#26649, peer #26596).
 */
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

    public function testLzfOptimizedForCallUsesJitLzf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/lzf/lzf_optimized_for.php');
        $this->assertStringContainsString('JitLzf::optimizedFor', $source);
        $this->assertStringNotContainsString('not implemented for JIT', $source);
    }

    public function testStringLzfCompilesLzfJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLzf.php');
        $this->assertStringContainsString('LzfJitHelper::compress', $source);
        $this->assertStringContainsString('LzfJitHelper::decompress', $source);
        $this->assertStringContainsString('/ext/lzf/LzfJitHelper.php', $source);
        $this->assertStringNotContainsString('NativeDlopen', $source);
        $this->assertStringNotContainsString('liblzf', $source);
    }

    public function testStringLzfUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLzf.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }

    /** AOT link no longer embeds bundled liblzf (#8805). */
    public function testLinkerDoesNotUseBundledLiblzf(): void
    {
        $linker = (string) file_get_contents(__DIR__.'/../../lib/AOT/Linker.php');
        $this->assertStringNotContainsString('ensureBundledLiblzf', $linker);
        $this->assertStringNotContainsString('bundledLiblzfLinkArg', $linker);
        $this->assertStringNotContainsString('liblzf.a', $linker);
    }

    public function testVmLzfUsesPurePhpCore(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/lzf/VmLzf.php');
        $this->assertStringContainsString('VmLzfCore::compress', $source);
        $this->assertStringContainsString('VmLzfCore::decompress', $source);
        $this->assertStringNotContainsString('VmLzfNative', $source);
    }

    /** Vendored liblzf C removed — VmLzfCore is sole implementation (#8852). */
    public function testThirdPartyLiblzfHasNoCompiledSources(): void
    {
        $dir = __DIR__.'/../../third_party/liblzf';
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir.'/LICENSE');
        $this->assertFileDoesNotExist($dir.'/lzf_c.c');
        $this->assertFileDoesNotExist($dir.'/lzf_d.c');
        $this->assertFileDoesNotExist($dir.'/lzfP.h');
        $this->assertFileDoesNotExist(__DIR__.'/../../script/build-liblzf.sh');
    }
}
