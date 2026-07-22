<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SysGetTempDirJitHelper;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/**
 * sys_get_temp_dir() JIT routes through SysGetTempDirJitHelper PHP (#9585).
 * NestedJIT via JitVmHelperLink::ensureCompiled (#22187 / peer #22147).
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

    public function testSysGetTempDirRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString('SysGetTempDirJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1);
    }

    public function testJitHelperMatchesVmNative(): void
    {
        $this->assertSame(VmSysGetTempDirNative::resolve(), SysGetTempDirJitHelper::resolve());
    }
}
