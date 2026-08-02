<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SysGetTempDirJitHelper;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/**
 * sys_get_temp_dir() AOT via libc getenv/realpath (#9585, #26929).
 */
final class SysGetTempDirRuntimeShrinkTest extends TestCase
{
    public function testSysGetTempDirJitHelperDelegatesToVmNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SysGetTempDirJitHelper.php');
        $this->assertStringContainsString('VmSysGetTempDirNative::resolve', $source);
    }

    public function testVmSysGetTempDirNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetTempDirNative.php');
        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetTempDirPure.php');
        $this->assertStringContainsString('VmSysGetTempDirPure::resolve', $native);
        $this->assertStringNotContainsString('FFI::cdef', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testSysGetTempDirRuntimeUsesLibcNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString("lookupFunction('getenv')", $source);
        $this->assertStringContainsString("lookupFunction('realpath')", $source);
        $this->assertStringContainsString('tryGetInsertBlock', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertLessThan(230, \substr_count($source, "\n") + 1);
    }

    public function testJitHelperMatchesVmNative(): void
    {
        $this->assertSame(VmSysGetTempDirNative::resolve(), SysGetTempDirJitHelper::resolve());
    }
}
