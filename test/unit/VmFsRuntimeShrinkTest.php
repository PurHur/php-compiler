<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDiskNative;
use PHPCompiler\ext\standard\VmFsPathNative;
use PHPCompiler\ext\standard\VmFsTouchNative;
use PHPCompiler\ext\standard\VmFsUnlink;
use PHPUnit\Framework\TestCase;

/** VmFs path/unlink/disk helpers without host Zend delegation (#7971, #7314 phase 2). */
final class VmFsRuntimeShrinkTest extends TestCase
{
    public function testVmFsPathNativeDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsPathNative.php');
        $this->assertStringContainsString('int rename(const char *oldpath', $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('rename'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('link'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('copy'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\rename\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\link\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\copy\\s*\\(/', $source);
    }

    public function testVmFsUnlinkDelegatesToPureWithoutLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsUnlink.php');
        $this->assertStringContainsString('VmFsUnlinkPure::unlink', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsUnlinkPure.php');
        $this->assertStringContainsString('@\\unlink', $pure);
    }

    public function testVmFsDiskSpaceDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsDiskNative::diskFreeSpace', $source);
        $this->assertStringContainsString('VmFsDiskNative::diskTotalSpace', $source);
        $this->assertStringContainsString('VmFsPathNative::readlink', $source);
        $this->assertStringContainsString('VmFsPathNative::symlink', $source);
        $this->assertStringContainsString('VmFsTouchNative::touch', $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('disk_free_space'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('disk_total_space'\\)/", $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\disk_free_space\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\disk_total_space\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\readlink\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\symlink\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\touch\\s*\\(/', $source);
    }

    public function testVmFsTouchNativeDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTouchNative.php');
        $this->assertStringContainsString('VmFsTouchPure::touch', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsTouchPure.php');
        $this->assertDoesNotMatchRegularExpression('/@\\\\touch\\s*\\(/', $source);
        $this->assertStringContainsString('@\\touch', $pure);
    }

    public function testPathOpsRequireFfiWhenHostDelegationDisabled(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext/ffi required');
        }
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmFsPathNative::rename('a', 'b'));
            $this->assertFalse(VmFsPathNative::copy('a', 'b'));
            $this->assertFalse(VmFsPathNative::link('a', 'b'));
            $this->assertFalse(VmFsPathNative::readlink('a'));
            $this->assertFalse(VmFsPathNative::symlink('a', 'b'));
            $touchPath = sys_get_temp_dir().'/phpc_touch_ffi_disabled_'.bin2hex(random_bytes(4)).'.tmp';
            try {
                $this->assertTrue(VmFsTouchNative::touch($touchPath, 100, 100));
                $this->assertTrue(VmFsUnlink::unlink($touchPath));
                $this->assertFileDoesNotExist($touchPath);
            } finally {
                @unlink($touchPath);
            }
            $this->assertFalse(VmFsUnlink::unlink('a'));
            if (VmFsDiskNative::available()) {
                $free = VmFs::diskFreeSpace(sys_get_temp_dir());
                $this->assertIsFloat($free);
            } else {
                $this->assertFalse(VmFs::diskFreeSpace(sys_get_temp_dir()));
                $this->assertFalse(VmFs::diskTotalSpace(sys_get_temp_dir()));
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testDiskSpaceViaPureMatchesHost(): void
    {
        if (!VmFsDiskNative::available()) {
            $this->markTestSkipped('disk_*() unavailable');
        }
        $path = sys_get_temp_dir();
        $free = VmFs::diskFreeSpace($path);
        $total = VmFs::diskTotalSpace($path);
        $this->assertIsFloat($free);
        $this->assertIsFloat($total);
        $this->assertGreaterThan(0.0, $free);
        $this->assertGreaterThan(0.0, $total);
    }
}
