<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsUnlink;
use PHPUnit\Framework\TestCase;

/** VM unlink() libc FFI without host unlink() delegation (#7314, #7971). */
final class VmFsUnlinkBootstrapTest extends TestCase
{
    public function testUnlinkWithoutFfiReturnsFalse(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_vm_unlink_boot_');
        $this->assertNotFalse($path);
        $this->assertFileExists($path);

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertFalse(VmFsUnlink::unlink($path));
            $this->assertFileExists($path);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
            @unlink($path);
        }
    }
}
