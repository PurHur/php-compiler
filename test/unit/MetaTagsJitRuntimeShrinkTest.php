<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_meta_tags JIT routes through MetaTagsJitHelper PHP, not hand-written LLVM (#9338).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (#26568, peer #26532).
 */
final class MetaTagsJitRuntimeShrinkTest extends TestCase
{
    public function testMetaTagsJitHelperDelegatesToVmMetaTags(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MetaTagsJitHelper.php');
        $this->assertStringContainsString('VmMetaTags::getMetaTagsHashTable', $source);
    }

    public function testMetaTagsRuntimeRoutesThroughMetaTagsJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('MetaTagsJitHelper', $source);
        $this->assertStringNotContainsString('implementParseMetaTagsHtml', $source);
        $this->assertStringNotContainsString('implementExtractAttribute', $source);
        $this->assertStringNotContainsString('implementNormalizeMetaName', $source);
        $this->assertStringNotContainsString('strncasecmp', $source);

        $lineCount = \substr_count($source, "\n");
        $this->assertLessThan(120, $lineCount, 'MetaTagsRuntime must be a thin bridge');
    }

    public function testMetaTagsRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
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

    public function testMetaTagsJitHelperSemanticsMatchVmMetaTags(): void
    {
        $html = '<html><head><meta name="author" content="phpc"></head></html>';
        $path = \sys_get_temp_dir().'/phpc_meta_tags_jit_helper_test.html';
        \file_put_contents($path, $html);

        $vm = \PHPCompiler\ext\standard\VmMetaTags::getMetaTags($path, false);
        $this->assertIsArray($vm);
        $ht = \PHPCompiler\ext\standard\MetaTagsJitHelper::getMetaTags($path, false);
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $ht);
        $key = new \PHPCompiler\VM\Variable();
        $key->string('author');
        $slot = $ht->findVariable($key, false);
        $this->assertNotNull($slot);
        $this->assertSame('phpc', $slot->toString());

        $missing = \PHPCompiler\ext\standard\MetaTagsJitHelper::getMetaTags(
            '/nonexistent/phpc_meta_tags_missing.html',
            false
        );
        $this->assertNull($missing);

        @\unlink($path);
    }
}
