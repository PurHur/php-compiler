<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPUnit\Framework\TestCase;

/** VmFsOpenNative libc open without host @fopen delegation (#5214). */
final class VmFsOpenNativeRuntimeShrinkTest extends TestCase
{
    public function testVmFsFopenRoutesThroughOpenNativeForRegularPaths(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsOpenNative::open', $source);
        $this->assertStringContainsString('VmFsOpenNative::available', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/VmFsStdio::isStdioUri[^}]+\$fp = @fopen\(\$path, \$mode\)/s',
            $source
        );
    }

    public function testVmFsReadfileUsesFopenNotHostOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('self::fopen($path, \'rb\')', $source);
        $this->assertStringContainsString('self::lookup($handle)', $source);
        $this->assertDoesNotMatchRegularExpression('/readfile\([^)]+\)\s*\{[^}]*@fopen\(\$path/s', $source);
    }

    public function testOpenNativeDeclaresLibcOpenDupClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsOpenNative.php');
        $this->assertStringContainsString('without host PHP @fopen', $source);
        $this->assertStringContainsString('int open(const char *pathname', $source);
        $this->assertStringContainsString('int dup(int oldfd)', $source);
        $this->assertStringContainsString('int close(int fd)', $source);
    }

    public function testFopenReadWriteRoundTrip(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsOpenNative libc open');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_fopen_');
        $this->assertNotFalse($path);
        @unlink($path);

        $writeHandle = VmFs::fopen($path, 'wb');
        $this->assertNotFalse($writeHandle);
        $written = VmFs::fwrite($writeHandle, 'xy');
        $this->assertSame(2, $written);
        VmFs::fclose($writeHandle);

        $readHandle = VmFs::fopen($path, 'rb');
        $this->assertNotFalse($readHandle);
        $this->assertSame('xy', VmFs::fread($readHandle, 10));
        VmFs::fclose($readHandle);

        @unlink($path);
    }

    public function testReadfileOutputsBytesAndReturnsCount(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsOpenNative libc open');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_rf_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'data');

        ob_start();
        $n = VmFs::readfile($path);
        ob_end_clean();

        $this->assertSame(4, $n);

        @unlink($path);
    }
}
