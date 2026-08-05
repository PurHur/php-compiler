<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * stream_get_meta_data / stream_set_blocking NestedJIT via JitVmHelperLink (#13846, #19678, #22994).
 */
final class StreamMetaKernelShrinkTest extends TestCase
{
    public function testBuiltinStreamMetaJitMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamMetaJit.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamMeta.php');
        $this->assertStringContainsString('JitStreamMetaKernel', $runtime);
        $this->assertStringNotContainsString('StreamMetaJit', $runtime);
    }

    public function testKernelUsesJitVmHelperLinkNotHandRolledNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamMetaKernel', $source);
        $this->assertStringContainsString('__compiler_stream_get_meta_data', $source);
        $this->assertStringContainsString('__compiler_stream_set_blocking', $source);
        $this->assertStringContainsString('__compiler_stream_enable_crypto', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('StreamMetaJitHelper', $source);
        $this->assertStringContainsString('JitStreamMetaThinAot', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(240, \substr_count($source, "\n") + 1);
    }

    public function testThinAotMetaLivesOutsideKernel(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamMetaThinAot.php');
        $thin = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaThinAot.php');
        $this->assertStringContainsString('phpc_stream_paths', $thin);
        $this->assertStringContainsString('__hashtable__setStringKeyString', $thin);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
        $this->assertStringNotContainsString('phpc_stream_handles', $kernel);
        $this->assertStringNotContainsString("lookupFunction('feof')", $kernel);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamMetaKernel.php', $spine);
        $this->assertStringContainsString('JitStreamMetaThinAot.php', $spine);
        $this->assertStringNotContainsString('StreamMetaJit.php', $spine);
        $this->assertStringContainsString('StreamMeta.php', $spine);
    }
}
