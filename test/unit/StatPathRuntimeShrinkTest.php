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
    }

    public function testStatPathJitHelperDelegatesToVmStatPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatPathJitHelper.php');
        $this->assertStringContainsString('VmStatPath::exists', $source);
        $this->assertStringContainsString('VmStatPath::isFile', $source);
        $this->assertStringContainsString('VmStatPath::isReadable', $source);
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
