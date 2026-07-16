<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * stream_get_meta_data / stream_set_blocking ABI bridges quarantined in ext/standard (#13846, #19678).
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

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamMetaKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamMetaKernel', $source);
        $this->assertStringContainsString('__compiler_stream_get_meta_data', $source);
        $this->assertStringContainsString('__compiler_stream_set_blocking', $source);
        $this->assertStringContainsString('__compiler_stream_enable_crypto', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamMetaKernel.php', $spine);
        $this->assertStringNotContainsString('StreamMetaJit.php', $spine);
        $this->assertStringContainsString('StreamMeta.php', $spine);
    }
}
