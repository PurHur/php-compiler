<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * fsync/fdatasync AOT via libc after stream resolve (#9815, #19660, #23004, #26929).
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

    public function testKernelUsesLibcFsyncNotNestedJitHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitStreamSyncKernel', $source);
        $this->assertStringContainsString('__compiler_fsync', $source);
        $this->assertStringContainsString('__compiler_fdatasync', $source);
        $this->assertStringContainsString('[\'fsync\', $i32, [$i32]]', $source);
        $this->assertStringContainsString('[\'fdatasync\', $i32, [$i32]]', $source);
        $this->assertStringContainsString('lookupFunction($syncName)', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('StreamSyncJitHelper', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 2)', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelNotBuiltinJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitStreamSyncKernel.php', $spine);
        $this->assertStringNotContainsString('StreamSyncJit.php', $spine);
        $this->assertStringContainsString('StreamSync.php', $spine);
    }
}
