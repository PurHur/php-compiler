<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * fsync/fdatasync ABI bridges quarantined in ext/standard (#9815, #19660).
 */
final class StreamSyncKernelShrinkTest extends TestCase
{
    public function testBuiltinStreamSyncJitMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StreamSyncJit.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSync.php');
        $this->assertStringContainsString('JitStreamSyncKernel', $runtime);
        $this->assertStringNotContainsString('StreamSyncJit', $runtime);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamSyncKernel', $source);
        $this->assertStringContainsString('__compiler_fsync', $source);
        $this->assertStringContainsString('__compiler_fdatasync', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamSyncKernel.php', $spine);
        $this->assertStringNotContainsString('StreamSyncJit.php', $spine);
        $this->assertStringContainsString('StreamSync.php', $spine);
    }
}
