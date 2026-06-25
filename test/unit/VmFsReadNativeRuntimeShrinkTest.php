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
        $this->assertStringContainsString('VmHttpFetchNative::fetch', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/@file_get_contents\\s*\\(/',
            $source,
            'VmFs must not delegate HTTP fetches to host @file_get_contents()'
        );
    }

    public function testVmFsFileDoesNotDelegateToHostFile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('readFileLines', $source);
        $this->assertStringContainsString('VmStatPath::isFile', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\file\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\is_file\\s*\\(/', $source);
    }

    public function testFileBuiltinRoundTripViaReadNative(): void
    {
        if (!VmFsReadNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsReadNative libc read');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_file_');
        $this->assertNotFalse($path);
        file_put_contents($path, "alpha\nbeta\n");

        $lines = VmFs::file($path);
        $this->assertIsArray($lines);
        $this->assertSame(["alpha\n", "beta\n"], $lines);

        $trimmed = VmFs::file($path, \PHPCompiler\ext\standard\StdlibConstants::FILE_IGNORE_NEW_LINES);
        $this->assertSame(['alpha', 'beta'], $trimmed);

        @unlink($path);
    }

    public function testFileZeroByteReturnsEmptyArray(): void
    {
        if (!VmFsReadNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsReadNative libc read');
        }

        $path = tempnam(sys_get_temp_dir(), 'phpc_file_empty_');
        $this->assertNotFalse($path);
        file_put_contents($path, '');

        $this->assertSame([], VmFs::file($path));
        $this->assertSame([], VmFs::file($path, \PHPCompiler\ext\standard\StdlibConstants::FILE_IGNORE_NEW_LINES));

        @unlink($path);
    }

    public function testReadNativeDeclaresLibcOpenReadClose(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsReadNative.php');
        $this->assertStringContainsString('VmFsReadPure', $source);
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

    public function testReadProcUptimeStreamsWithoutLseek(): void
    {
        if (!VmFsReadNative::available()) {
            $this->markTestSkipped('ext/ffi required for VmFsReadNative libc read');
        }
        if ('Linux' !== \PHP_OS_FAMILY || !\is_readable('/proc/uptime')) {
            $this->markTestSkipped('/proc/uptime unavailable');
        }

        $raw = VmFsReadNative::read('/proc/uptime');
        $this->assertIsString($raw);
        $this->assertNotSame('', $raw);
        $this->assertMatchesRegularExpression('/^\d+\.\d+ /', $raw);
    }

    /** @return list<string> */
    private function vmNativeReadSites(): array
    {
        return [
            'ext/standard/VmHrtimeNative.php',
            'ext/standard/VmEnvEnvironNative.php',
            'ext/standard/VmMemory.php',
            'ext/standard/VmIptc.php',
            'ext/standard/VmSession.php',
            'ext/standard/VmParseIni.php',
            'ext/standard/VmDns.php',
            'ext/standard/VmDateTimeNative.php',
            'ext/zip/ZipEngine.php',
            'ext/zip/VmZipArchive.php',
        ];
    }

    public function testVmNativeHelpersRouteProcAndFileReadsThroughReadNative(): void
    {
        foreach ($this->vmNativeReadSites() as $relativePath) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$relativePath);
            $usesNativeRead = str_contains($source, 'VmFsReadNative::read')
                || str_contains($source, 'VmFs::file');
            $this->assertTrue(
                $usesNativeRead,
                "{$relativePath} must use VmFsReadNative::read() or VmFs::file()"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\\\\file_get_contents\\s*\\(/',
                $source,
                "{$relativePath} must not call host \\file_get_contents()"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!\\\\)file_get_contents\\s*\\(/',
                $source,
                "{$relativePath} must not call host file_get_contents()"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/@\\\\file\\s*\\(/',
                $source,
                "{$relativePath} must not call host \\file()"
            );
        }
    }
}
