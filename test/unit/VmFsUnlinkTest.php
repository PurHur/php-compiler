<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsUnlink;
use PHPUnit\Framework\TestCase;

/** @covers issue #5063 */
final class VmFsUnlinkTest extends TestCase
{
    public function testLibcUnlinkRemovesFile(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension required for VmFsUnlink');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc_vm_unlink_');
        $this->assertNotFalse($path);
        $this->assertFileExists($path);
        $this->assertTrue(VmFsUnlink::unlink($path));
        $this->assertFileDoesNotExist($path);
        $this->assertFalse(VmFsUnlink::unlink($path));
    }

    public function testNullBytePathRejected(): void
    {
        $this->assertFalse(VmFsUnlink::unlink("a\0b"));
    }
}
