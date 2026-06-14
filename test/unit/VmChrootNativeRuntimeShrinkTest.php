<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmChrootNative;
use PHPUnit\Framework\TestCase;

/** VmChrootNative libc chroot without host \\chroot() (#3500). */
final class VmChrootNativeRuntimeShrinkTest extends TestCase
{
    public function testVmChrootNativeDeclaresLibcChroot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmChrootNative.php');
        $this->assertStringContainsString('without host \\chroot()', $source);
        $this->assertStringContainsString('int chroot(const char *path)', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\chroot\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression("/function_exists\\('chroot'\\)/", $source);
    }

    public function testChrootReturnsFalseWhenFfiDisabled(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext/ffi required');
        }
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmChrootNative::chroot(sys_get_temp_dir()));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testChrootDeniedForTypicalUnprivilegedProcess(): void
    {
        if (!VmChrootNative::available()) {
            $this->markTestSkipped('FFI chroot unavailable');
        }
        $this->assertFalse(VmChrootNative::chroot('/no/such/phpc-chroot-native-path'));
    }
}
