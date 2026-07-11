<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPCompiler\ext\standard\VmFsOpenPure;
use PHPUnit\Framework\TestCase;

/** VmFsOpenNative libc open without host @fopen delegation (#5214, #8517). */
final class VmFsOpenNativeRuntimeShrinkTest extends TestCase
{
    public function testVmFsFopenRoutesThroughOpenNativeForRegularPaths(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsOpenNative::open', $source);
        $this->assertStringContainsString('VmFsOpenNative::available', $source);
        $this->assertDoesNotMatchRegularExpression('/@fopen\s*\(/', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/VmFsStdio::isStdioUri[^}]+\$fp = @fopen\(\$path, \$mode\)/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/VmFsOpenNative::available\(\)[^}]*@fopen\(\$path, \$mode\)/s',
            $source
        );
    }

    public function testFopenUnknownPhpStreamReturnsFalse(): void
    {
        $this->assertFalse(VmFs::fopen('php://unknown-runtime-shrink-scheme', 'r'));
    }

    public function testFopenPhpMemoryStillNative(): void
    {
        $handle = VmFs::fopen('php://memory', 'r+');
        $this->assertNotFalse($handle);
        VmFs::fclose($handle);
    }

    public function testFopenReturnsFalseWhenOpenNativeUnavailable(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext/ffi required to test VmFsOpenNative FFI gate');
        }
        if (!VmFsOpenPure::available()) {
            $this->markTestSkipped('host fopen required for VmFsOpenPure fallback');
        }
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $path = tempnam(sys_get_temp_dir(), 'phpc_fopen_gate_');
            $this->assertNotFalse($path);
            $this->assertTrue(VmFsOpenNative::available());
            $handle = VmFs::fopen($path, 'rb');
            $this->assertNotFalse($handle);
            if (false !== $handle) {
                VmFs::fclose($handle);
            }
            @unlink($path);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testVmFsReadfileUsesFopenNotHostOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('self::fopen($path, \'rb\')', $source);
        $this->assertStringContainsString('passthruHandleToStdout', $source);
        $this->assertDoesNotMatchRegularExpression('/readfile\([^)]+\)\s*\{[^}]*@fopen\(\$path/s', $source);
    }

    public function testOpenNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsOpenNative.php');
        $this->assertStringContainsString('VmFsOpenPure::open', $source);
        $this->assertStringContainsString('VmFsOpenPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('int open(const char *pathname', $source);
    }

    public function testFopenReadWriteRoundTrip(): void
    {
        if (!VmFsOpenNative::available()) {
            $this->markTestSkipped('host fopen unavailable for VmFsOpenPure');
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
            $this->markTestSkipped('host fopen unavailable for VmFsOpenPure');
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
