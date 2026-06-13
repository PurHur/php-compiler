<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsReadNative;
use PHPUnit\Framework\TestCase;

/** VmFsReadNative libc read without host file_get_contents delegation (#1492). */
final class VmFsReadNativeRuntimeShrinkTest extends TestCase
{
    public function testVmFsFileGetContentsRoutesThroughReadNativeForLocalPaths(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsReadNative::read', $source);
        $this->assertStringContainsString('VmHttpLastResponseHeaders::isHttpUrl', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/return VmFsReadNative::read[^;]+;\s*\n\s*\$data = @file_get_contents/s',
            $source
        );
    }

    public function testReadNativeDeclaresLibcOpenReadClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsReadNative.php');
        $this->assertStringContainsString('without host PHP', $source);
        $this->assertStringContainsString('int open(const char *pathname', $source);
        $this->assertStringContainsString('ssize_t read(int fd', $source);
        $this->assertStringContainsString('int close(int fd)', $source);
    }

    public function testReadRoundTrip(): void
    {
        if (!VmFsReadNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsReadNative libc read');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_read_');
        $this->assertNotFalse($path);
        file_put_contents($path, 'hello-native-read');

        $this->assertSame('hello-native-read', VmFsReadNative::read($path));
        $this->assertSame('hello-native-read', VmFs::fileGetContents($path));

        @unlink($path);
    }
}
