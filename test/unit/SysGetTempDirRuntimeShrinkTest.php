<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SysGetTempDirJitHelper;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/** sys_get_temp_dir() JIT routes through SysGetTempDirJitHelper PHP not StringFsDirJit LLVM (#9585). */
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

    public function testSysGetTempDirRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString('SysGetTempDirJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);
    }

    public function testJitHelperMatchesVmNative(): void
    {
        $this->assertSame(VmSysGetTempDirNative::resolve(), SysGetTempDirJitHelper::resolve());
    }
}
