<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FsDirJitHelper;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPCompiler\ext\standard\VmFsTouchNative;
use PHPUnit\Framework\TestCase;

/**
 * touch/mkdir/tempnam JIT — mkdir/tempnam via FsDirJitHelper NestedJIT; touch via
 * TouchLibcRuntime libc utime (#8999 / #25976 / #28995).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #25570).
 */
final class FsDirRuntimeShrinkTest extends TestCase
{
    public function testStringFsDirJitDelegatesTouchMkdirTempnamToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('FsDirRuntime::ensureLinked', $source);
        $this->assertStringContainsString('CopyRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitCopy', $source);
        $this->assertStringNotContainsString("lookupFunction('fopen')", $source);
        $this->assertStringNotContainsString("lookupFunction('fread')", $source);
        $this->assertStringNotContainsString('emitTouch', $source);
        $this->assertStringNotContainsString('emitMkdir', $source);
        $this->assertStringNotContainsString('emitTempnam', $source);
        $this->assertStringNotContainsString('emitSysGetTempDir', $source);
        $this->assertStringContainsString('SysGetTempDirRuntime::ensureLinked', $source);
        $this->assertStringContainsString('StatArrayRuntime::ensureLinked', $source);
        $this->assertStringContainsString('FtokRuntime::ensureLinked', $source);
        $this->assertStringContainsString('ChownRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitStat', $source);
        $this->assertStringNotContainsString('emitFtok', $source);
        $this->assertStringNotContainsString('emitChown', $source);
        $this->assertStringNotContainsString('emitChgrp', $source);
        $this->assertStringNotContainsString("lookupFunction('chown')", $source);
        $this->assertStringNotContainsString("lookupFunction('fchownat')", $source);
        $this->assertStringNotContainsString("lookupFunction('getpwnam')", $source);
        $this->assertStringNotContainsString("lookupFunction('mkstemp')", $source);
    }

    public function testFsDirRuntimeUsesJitVmHelperLinkForMkdirTempnam(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FsDirRuntime.php');
        $this->assertStringContainsString('FsDirJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('TouchLibcRuntime::emit', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(290, $lineCount);
        $this->assertGreaterThan(10, 320 - $lineCount);
    }

    public function testTouchLibcRuntimeDeclaresUtime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TouchLibcRuntime.php');
        $this->assertStringContainsString("lookupFunction('utime')", $source);
        $this->assertStringContainsString('TOUCH_TIME_OMIT', $source);
        $this->assertStringContainsString('#28995', $source);
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
