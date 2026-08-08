<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsPathNative;
use PHPCompiler\ext\standard\VmFsPathPure;
use PHPUnit\Framework\TestCase;

/** VmFsPathPure — rename/copy/link without libc FFI (#5213, #12316). */
final class VmFsPathPureRuntimeShrinkTest extends TestCase
{
    public function testVmFsPathNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsPathNative.php');
        $this->assertStringContainsString('VmFsPathPure::rename', $source);
        $this->assertStringContainsString('VmFsPathPure::copy', $source);
        $this->assertStringContainsString('VmFsPathPure::link', $source);
        $this->assertStringContainsString('VmFsPathPure::readlink', $source);
        $this->assertStringContainsString('VmFsPathPure::symlink', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int rename(const char *oldpath', $source);
        $this->assertStringNotContainsString('symlinkat', $source);
    }

    public function testVmFsPathPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsPathPure.php');
        $this->assertStringContainsString('rename', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int rename(const char', $source);
    }

    public function testRenameJitHelperUsesRenameKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RenameJitHelper.php');
        $this->assertStringContainsString('phpc_rename_kernel', $source);
        $this->assertStringContainsString('VmStatCache::invalidatePath', $source);
        $this->assertStringNotContainsString('VmFsPathPure::rename', $source);
        $this->assertStringNotContainsString('return VmFs::rename', $source);
    }

    public function testRenameCopyLinkRoundTripViaNativeDelegate(): void
    {
        if (!VmFsPathPure::available()) {
            $this->markTestSkipped('host rename unavailable');
        }

        $dir = sys_get_temp_dir();
        $src = tempnam($dir, 'phpc_path_pure_');
        $this->assertNotFalse($src);
        file_put_contents($src, 'payload');

        $copyDst = $dir.'/phpc_path_pure_copy_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::copy($src, $copyDst));
        $this->assertSame('payload', file_get_contents($copyDst));

        $renamed = $dir.'/phpc_path_pure_renamed_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::rename($copyDst, $renamed));
        $this->assertFileDoesNotExist($copyDst);
        $this->assertSame('payload', file_get_contents($renamed));

        $hard = $dir.'/phpc_path_pure_hard_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::link($src, $hard));
        $this->assertSame('payload', file_get_contents($hard));

        $sym = $dir.'/phpc_path_pure_sym_'.bin2hex(random_bytes(4));
        $this->assertTrue(VmFsPathNative::symlink($src, $sym));
        $this->assertSame($src, VmFsPathNative::readlink($sym));

        @unlink($src);
        @unlink($renamed);
        @unlink($hard);
        @unlink($sym);
    }
}
