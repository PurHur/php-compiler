<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_meta_tags JIT routes through MetaTagsJitHelper PHP, not hand-written LLVM (#9338, #33051).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (#26568).
 * Thin AOT uses native HT materializer (not NestedJIT HashTable return — #27551 / #26942).
 */
final class MetaTagsJitRuntimeShrinkTest extends TestCase
{
    public function testMetaTagsJitHelperUsesNativeHtMaterializer(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/MetaTagsJitHelper.php');
        $this->assertStringContainsString('phpc_native_ht_alloc', $source);
        $this->assertStringContainsString('phpc_native_ht_set_string_key', $source);
        $this->assertStringContainsString('@file_get_contents', $source);
        $this->assertStringContainsString('\'\' === $ch', $source);
        $this->assertStringNotContainsString('isset($html', $source);
        $this->assertStringContainsString(': int', $source);
        $this->assertStringNotContainsString('getMetaTagsHashTable', $source);
        $this->assertStringNotContainsString('new HashTable', $source);
    }

    public function testMetaTagsRuntimeRoutesThroughMetaTagsJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('MetaTagsJitHelper', $source);
        $this->assertStringContainsString('i64ToTypedPtr', $source);
        $this->assertStringContainsString('ensureNativeHtInternalProxies', $source);
        $this->assertStringNotContainsString('implementParseMetaTagsHtml', $source);
        $this->assertStringNotContainsString('coerceToHashtablePtr', $source);

        $lineCount = \substr_count($source, "\n");
        $this->assertLessThan(180, $lineCount, 'MetaTagsRuntime must be a thin bridge');
    }

    public function testMetaTagsRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MetaTagsRuntime.php');
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
    }

    public function testJitGetMetaTagsStillUsesCompilerGetMetaTagsAbi(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitGetMetaTags.php');
        $this->assertStringContainsString('__compiler_get_meta_tags', $source);
        $this->assertStringContainsString('MetaTagsRuntime::ensureLinked', $source);
    }

    public function testVmMetaTagsSemanticsForSsot(): void
    {
        $html = '<html><head><meta name="author" content="phpc"></head></html>';
        $path = \sys_get_temp_dir().'/phpc_meta_tags_jit_helper_test.html';
        \file_put_contents($path, $html);

        $vm = \PHPCompiler\ext\standard\VmMetaTags::getMetaTags($path, false);
        $this->assertIsArray($vm);
        $this->assertSame('phpc', $vm['author'] ?? null);

        $missing = \PHPCompiler\ext\standard\VmMetaTags::getMetaTags(
            '/nonexistent/phpc_meta_tags_missing.html',
            false
        );
        $this->assertFalse($missing);

        @\unlink($path);
    }
}
