<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_meta_tags JIT routes through MetaTagsJitHelper PHP, not hand-written LLVM (#9338, #33035).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureBridge} (#26568 / #33035).
 */
final class MetaTagsJitRuntimeShrinkTest extends TestCase
{
    public function testMetaTagsJitHelperIsSameFileNestedJitSafe(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MetaTagsJitHelper.php');
        $this->assertStringContainsString('#33035', $source);
        $this->assertStringContainsString('@\\file_get_contents', $source);
        $this->assertStringContainsString('self::extractAttribute', $source);
        $this->assertStringContainsString(': ?array', $source);
        $this->assertStringNotContainsString('VmMetaTags::', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\VM\\HashTable', $source);
    }

    public function testMetaTagsRuntimeOwnsAbiViaEnsureBridge(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('#33035', $source);
        $this->assertStringContainsString('MetaTagsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('getNamedFunction', $source);
        $this->assertStringContainsString('addFunction', $source);
        $this->assertStringNotContainsString('implementParseMetaTagsHtml', $source);
        $this->assertStringNotContainsString('strncasecmp', $source);

        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(100, $lineCount, 'MetaTagsRuntime must be a thin bridge');
    }

    public function testMetaTagsRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("putenv('PHP_COMPILER_SELFHOST_AOT", $source);
    }

    public function testJitGetMetaTagsStillUsesCompilerGetMetaTagsAbi(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitGetMetaTags.php');
        $this->assertStringContainsString('__compiler_get_meta_tags', $source);
        $this->assertStringContainsString('MetaTagsRuntime::ensureLinked', $source);
    }

    public function testGetMetaTagsCallFoldsLiteralPathsViaVmMetaTags(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/get_meta_tags.php');
        $this->assertStringContainsString('VmMetaTags::getMetaTags', $source);
        $this->assertStringContainsString('compileTimeLiteral', $source);
        $this->assertStringContainsString('#33035', $source);
    }

    public function testMetaTagsJitHelperSemanticsMatchVmMetaTags(): void
    {
        $html = '<html><head><meta name="author" content="phpc"></head></html>';
        $path = \sys_get_temp_dir().'/phpc_meta_tags_jit_helper_test.html';
        \file_put_contents($path, $html);

        $vm = \PHPCompiler\ext\standard\VmMetaTags::getMetaTags($path, false);
        $this->assertIsArray($vm);
        $arr = \PHPCompiler\ext\standard\MetaTagsJitHelper::getMetaTags($path, false);
        $this->assertIsArray($arr);
        $this->assertSame('phpc', $arr['author'] ?? null);
        $this->assertSame($vm['author'], $arr['author']);

        $missing = \PHPCompiler\ext\standard\MetaTagsJitHelper::getMetaTags(
            '/nonexistent/phpc_meta_tags_missing.html',
            false
        );
        $this->assertNull($missing);

        @\unlink($path);
    }
}
