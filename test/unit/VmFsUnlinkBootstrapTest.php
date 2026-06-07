<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsUnlink;
use PHPUnit\Framework\TestCase;

/** VM unlink() without ext/ffi (#7314). */
final class VmFsUnlinkBootstrapTest extends TestCase
{
    public function testUnlinkWithoutFfiViaHostLibc(): void
    {
        if (!\function_exists('unlink')) {
            $this->markTestSkipped('host unlink() unavailable');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc_vm_unlink_boot_');
        $this->assertNotFalse($path);
        $this->assertFileExists($path);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmFsUnlink::unlink($path));
            $this->assertFileDoesNotExist($path);
            $this->assertFalse(VmFsUnlink::unlink($path));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
