<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FsDirJitHelper;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPCompiler\ext\standard\VmFsTouchNative;
use PHPUnit\Framework\TestCase;

/** touch/mkdir/tempnam JIT routes through FsDirJitHelper PHP not StringFsDirJit libc LLVM (#8999). */
final class FsDirRuntimeShrinkTest extends TestCase
{
    public function testStringFsDirJitDelegatesTouchMkdirTempnamToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('FsDirRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitTouch', $source);
        $this->assertStringNotContainsString('emitMkdir', $source);
        $this->assertStringNotContainsString('emitTempnam', $source);
        $this->assertStringNotContainsString('emitSysGetTempDir', $source);
        $this->assertStringContainsString('SysGetTempDirRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString("lookupFunction('utime')", $source);
        $this->assertStringNotContainsString("lookupFunction('mkstemp')", $source);
    }

    public function testFsDirRuntimeUsesJitHelperNotLlvmLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FsDirRuntime.php');
        $this->assertStringContainsString('FsDirJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString("lookupFunction('mkdir')", $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testFsDirJitHelperTouchMatchesVmFs(): void
    {
        $path = sys_get_temp_dir().'/phpc_fsdir_touch_'.getmypid();
        @unlink($path);
        FsDirJitHelper::resetForTest();
        $this->assertTrue(FsDirJitHelper::touch($path, -1, -1));
        $this->assertTrue(is_file($path));
        $this->assertSame(VmFsTouchNative::touch($path, 100, 100), FsDirJitHelper::touch($path, 100, 100));
        @unlink($path);
    }

    public function testFsDirJitHelperMkdirMatchesVmFs(): void
    {
        $dir = sys_get_temp_dir().'/phpc_fsdir_mkdir_'.getmypid();
        @rmdir($dir);
        FsDirJitHelper::resetForTest();
        $this->assertTrue(FsDirJitHelper::mkdir($dir, 0700, false));
        $this->assertTrue(is_dir($dir));
        $this->assertSame(VmFsDirNative::mkdir($dir, 0700, false), FsDirJitHelper::mkdir($dir, 0700, false));
        @rmdir($dir);
    }
}
