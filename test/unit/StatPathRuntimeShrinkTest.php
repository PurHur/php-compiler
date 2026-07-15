<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** JitStat routes through StatPathJitHelper/StatFieldsJitHelper PHP, not glibc struct stat LLVM (#9112). */
final class StatPathRuntimeShrinkTest extends TestCase
{
    public function testJitStatUsesStatPathRuntimeNotGlibcOffsets(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStat.php');
        $this->assertStringContainsString('StatPathRuntime::', $source);
        $this->assertStringNotContainsString('STAT_BUF_SIZE', $source);
        $this->assertStringNotContainsString('STAT_MODE_OFFSET', $source);
        $this->assertStringNotContainsString("lookupFunction('stat')", $source);
        $this->assertStringNotContainsString("lookupFunction('lstat')", $source);
        $this->assertStringNotContainsString("lookupFunction('access')", $source);
        $this->assertStringNotContainsString('STATVFS_', $source);
        $this->assertLessThan(400, \substr_count($source, "\n"), 'JitStat must shrink after PHP bridge migration');
    }

    public function testStatPathRuntimeUsesJitHelpers(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StatPathRuntime.php');
        $this->assertStringContainsString('StatPathJitHelper', $source);
        $this->assertStringContainsString('StatFieldsJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringContainsString('JitStatKernel', $source);
        $this->assertStringNotContainsString('StatPathRuntimeLibc', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StatPathRuntimeLibc.php');
    }

    public function testStatPathJitHelperUsesStatModeKernelNotExternalVmStatPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatPathJitHelper.php');
        $this->assertStringContainsString('phpc_stat_mode_kernel', $source);
        $this->assertStringContainsString('phpc_access_kernel', $source);
        $this->assertStringNotContainsString('VmStatPath::', $source);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStatKernel.php');
        $this->assertStringContainsString("lookupFunction(\$statFn)", $kernel);
        $this->assertStringContainsString("lookupFunction('access')", $kernel);
        $this->assertStringContainsString('STAT_MODE_OFFSET', $kernel);
    }

    public function testStatFieldsJitHelperDelegatesToVmStatCache(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatFieldsJitHelper.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmStatCache::lstat', $source);
        $this->assertStringContainsString('VmFs::fileType', $source);
        $this->assertStringContainsString('VmFsDiskNative::', $source);
    }
}
