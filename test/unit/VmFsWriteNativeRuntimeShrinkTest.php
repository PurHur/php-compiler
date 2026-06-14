<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPCompiler\ext\standard\VmFsWriteNative;
use PHPUnit\Framework\TestCase;

/** VmFsWriteNative libc write without host file_put_contents delegation (#8487). */
final class VmFsWriteNativeRuntimeShrinkTest extends TestCase
{
    public function testVmFsFilePutContentsRoutesThroughWriteNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsWriteNative::write', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function filePutContents[^{]+\{[^}]*@file_put_contents/s',
            $source
        );
    }

    public function testWriteNativeDeclaresLibcOpenWriteFlockClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsWriteNative.php');
        $this->assertStringContainsString('without host PHP @file_put_contents', $source);
        $this->assertStringContainsString('int open(const char *pathname', $source);
        $this->assertStringContainsString('ssize_t write(int fd', $source);
        $this->assertStringContainsString('int flock(int fd', $source);
        $this->assertStringContainsString('int close(int fd)', $source);
    }

    public function testWriteRoundTrip(): void
    {
        if (!VmFsWriteNative::available() || !VmFsReadNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsWriteNative libc write');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_write_');
        $this->assertNotFalse($path);

        $this->assertSame(18, VmFsWriteNative::write($path, 'hello-native-write'));
        $this->assertSame('hello-native-write', VmFsReadNative::read($path));
        $this->assertSame(15, VmFs::filePutContents($path, 'via-vmfs-helper'));
        $this->assertSame('via-vmfs-helper', VmFs::fileGetContents($path));

        @unlink($path);
    }

    public function testWriteAppendAndLockEx(): void
    {
        if (!VmFsWriteNative::available() || !VmFsReadNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsWriteNative libc write');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fpc_');
        $this->assertNotFalse($path);

        $this->assertSame(2, VmFs::filePutContents($path, 'ab', \LOCK_EX));
        $this->assertSame(2, VmFs::filePutContents($path, 'cd', \FILE_APPEND | \LOCK_EX));
        $this->assertSame('abcd', VmFs::fileGetContents($path));

        @unlink($path);
    }
}
