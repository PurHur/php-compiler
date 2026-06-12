<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPUnit\Framework\TestCase;

/** VmFs mkdir/rmdir/chmod/chown/chgrp without host Zend delegation (pairs StringFsDirJit). */
final class VmFsDirNativeRuntimeShrinkTest extends TestCase
{
    public function testVmFsDirNativeDeclaresLibcSyscalls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsDirNative.php');
        $this->assertStringContainsString('int mkdir(const char *pathname', $source);
        $this->assertStringContainsString('int rmdir(const char *pathname', $source);
        $this->assertStringContainsString('int chmod(const char *pathname', $source);
        $this->assertStringContainsString('int chown(const char *pathname', $source);
        $this->assertStringContainsString('int fchownat(int dirfd', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\mkdir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\rmdir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chmod\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chown\\s*\\(/', $source);
    }

    public function testVmFsDoesNotDelegateDirOpsToHostPhp(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsDirNative::mkdir', $source);
        $this->assertStringContainsString('VmFsDirNative::rmdir', $source);
        $this->assertStringContainsString('VmFsDirNative::chmod', $source);
        $this->assertStringContainsString('VmFsDirNative::chown', $source);
        $this->assertStringContainsString('VmFsDirNative::lchown', $source);
        $this->assertStringContainsString('VmFsDirNative::chgrp', $source);
        $this->assertStringContainsString('VmFsDirNative::lchgrp', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\mkdir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\rmdir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chmod\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chown\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\lchown\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chgrp\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\lchgrp\\s*\\(/', $source);
    }

    public function testMkdirRmdirRoundTripWhenFfiAvailable(): void
    {
        if (!VmFsDirNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }
        $base = sys_get_temp_dir().'/phpc_vfs_dir_'.bin2hex(random_bytes(4));
        $nested = $base.'/a/b';
        $this->assertTrue(VmFs::mkdir($nested, 0755, true));
        $this->assertTrue(is_dir($nested));
        $this->assertTrue(VmFs::rmdir($base.'/a/b'));
        $this->assertTrue(VmFs::rmdir($base.'/a'));
        $this->assertTrue(VmFs::rmdir($base));
    }

    public function testDirOpsReturnFalseWhenFfiDisabled(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext/ffi required');
        }
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmFs::mkdir('/tmp/phpc_disabled', 0755, false));
            $this->assertFalse(VmFs::rmdir('/tmp/phpc_disabled'));
            $this->assertFalse(VmFs::chmod('/tmp', 0755));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
