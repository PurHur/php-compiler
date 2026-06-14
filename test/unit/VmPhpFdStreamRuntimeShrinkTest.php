<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPUnit\Framework\TestCase;

/** VM fd streams must not delegate dup'd fds to host @fopen('php://fd/…') (#8533). */
final class VmPhpFdStreamRuntimeShrinkTest extends TestCase
{
    public function testNativeOpenersDoNotUseHostPhpFdFopen(): void
    {
        $files = [
            'VmFsOpenNative.php',
            'VmFsStdioNative.php',
            'VmPopenNative.php',
            'VmTmpfileNative.php',
            'VmStreamSocketNative.php',
            'VmStreamSocketPairNative.php',
        ];
        foreach ($files as $basename) {
            $source = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$basename);
            $this->assertDoesNotMatchRegularExpression(
                "/@\\\\?fopen\\s*\\(\\s*['\"]php:\\/\\/fd\\//",
                $source,
                "{$basename} must not call host @fopen on php://fd/"
            );
        }
    }

    public function testVmFsStdioHasNoHostFallback(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsStdio.php');
        $this->assertStringContainsString('VmFsStdioNative::openDupFd', $source);
        $this->assertDoesNotMatchRegularExpression('/@fopen\\s*\\(/', $source);
    }

    public function testVmFsFopenPhpFdUriUsesNativeFdStream(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmPhpFdStream::openFromUri', $source);
        $this->assertDoesNotMatchRegularExpression(
            "/@\\\\?fopen\\s*\\(\\s*['\"]php:\\/\\/fd\\//",
            $source,
            'VmFs::fopen must not call host @fopen on php://fd/'
        );
    }

    public function testFopenPhpFdUriRoundTrip(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsOpenNative libc open');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_phpfd_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'php-fd-uri');

        $baseHandle = VmFs::fopen($path, 'rb');
        $this->assertNotFalse($baseHandle);
        $osFd = VmPhpFdStream::fdForHandle($baseHandle);
        $this->assertNotNull($osFd);

        $fdHandle = VmFs::fopen('php://fd/'.$osFd, 'rb');
        $this->assertNotFalse($fdHandle);
        $this->assertTrue(VmPhpFdStream::isValidHandle($fdHandle));
        $this->assertSame('php-fd-uri', VmFs::fread($fdHandle, 8192));

        VmFs::fclose($fdHandle);
        VmFs::fclose($baseHandle);
        @unlink($path);
    }

    public function testVmPhpFdStreamUsesLibcReadWrite(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPhpFdStream.php');
        $this->assertStringContainsString('function adopt', $source);
        $this->assertStringContainsString('ssize_t read(int fd', $source);
        $this->assertStringContainsString('ssize_t write(int fd', $source);
        $this->assertDoesNotMatchRegularExpression('/^\s*[^\/\*].*@fopen\\s*\\(/m', $source);
    }

    public function testFopenReadWriteRoundTripViaFdStream(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsOpenNative libc open');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fd_');
        $this->assertNotFalse($path);
        @unlink($path);

        $writeHandle = VmFs::fopen($path, 'wb');
        $this->assertNotFalse($writeHandle);
        $this->assertTrue(VmPhpFdStream::isValidHandle($writeHandle));
        $written = VmFs::fwrite($writeHandle, 'fd-native');
        $this->assertSame(9, $written);
        VmFs::fclose($writeHandle);

        $readHandle = VmFs::fopen($path, 'rb');
        $this->assertNotFalse($readHandle);
        $this->assertTrue(VmPhpFdStream::isValidHandle($readHandle));
        $this->assertSame('fd-native', VmFs::fread($readHandle, 8192));
        VmFs::fclose($readHandle);
        @unlink($path);
    }
}
