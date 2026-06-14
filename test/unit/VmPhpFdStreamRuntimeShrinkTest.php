<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPUnit\Framework\TestCase;

/** VmPhpFdStream flock/fflush/fsync via libc FFI — no host @flock on fd streams (#8594). */
final class VmPhpFdStreamRuntimeShrinkTest extends TestCase
{
    public function testVmPhpFdStreamDeclaresLibcFlockFsync(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPhpFdStream.php');
        $this->assertStringContainsString('no host PHP @flock', $source);
        $this->assertStringContainsString('int flock(int fd', $source);
        $this->assertStringContainsString('int fsync(int fd', $source);
        $this->assertStringContainsString('int fdatasync(int fd', $source);
    }

    public function testVmFsRoutesFlockThroughFdStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmPhpFdStream::flock($handle', $source);
        $this->assertStringContainsString('VmPhpFdStream::fsync($handle', $source);
    }

    public function testFlockExclusiveOnNativeFopenPath(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsOpenNative');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fd_flock_');
        $this->assertNotFalse($path);

        $handle = VmFs::fopen($path, 'c+');
        $this->assertNotFalse($handle);
        $this->assertTrue(VmPhpFdStream::isValidHandle($handle));

        $this->assertTrue(VmFs::flock($handle, \LOCK_EX));
        $this->assertTrue(VmFs::flock($handle, \LOCK_UN));
        VmFs::fclose($handle);
        @unlink($path);
    }

    public function testFsyncOnNativeFopenPath(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsOpenNative');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fd_fsync_');
        $this->assertNotFalse($path);

        $handle = VmFs::fopen($path, 'w');
        $this->assertNotFalse($handle);
        VmFs::fwrite($handle, 'sync-me');
        $this->assertTrue(VmFs::fsync($handle));
        VmFs::fclose($handle);
        $this->assertSame('sync-me', VmFs::fileGetContents($path));
        @unlink($path);
    }
}
