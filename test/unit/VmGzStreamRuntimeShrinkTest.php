<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmGzStream;
use PHPCompiler\ext\standard\VmGzStreamNative;
use PHPCompiler\ext\standard\VmGzStreamPure;
use PHPUnit\Framework\TestCase;

/** VmGzStream buffered I/O without libz gzFile FFI (#8936, #6168, #8220). */
final class VmGzStreamRuntimeShrinkTest extends TestCase
{
    public function testVmGzStreamUsesPureBackendWithoutHostDelegation(): void
    {
        $stream = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGzStream.php');
        $this->assertStringContainsString('VmGzStreamNative', $stream);
        $this->assertStringNotContainsString('function_exists(', $stream);
        $this->assertStringNotContainsString('\\gzopen(', $stream);
        $this->assertStringNotContainsString('\\gzwrite(', $stream);
        $this->assertStringNotContainsString('\\gzread(', $stream);
        $this->assertStringNotContainsString('\\gzclose(', $stream);

        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGzStreamNative.php');
        $this->assertStringContainsString('VmGzStreamPure::gzopen', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('$ffi->gzopen', $native);
        $this->assertStringNotContainsString('\\gzopen(', $native);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGzStreamPure.php');
        $this->assertStringNotContainsString('\\FFI', $pure);
        $this->assertStringContainsString('VmZlib::gzencode', $pure);
        $this->assertStringContainsString('VmZlib::gzdecode', $pure);
    }

    public function testVmGzStreamNativeDelegatesToPure(): void
    {
        $this->assertSame(VmGzStreamPure::available(), VmGzStreamNative::available());
    }

    public function testLibzGzStreamAvailableOnHarness(): void
    {
        if (!VmGzStreamNative::available()) {
            $this->markTestSkipped('VmZlib backend unavailable on this host');
        }

        $path = sys_get_temp_dir().'/phpc_gz_native_'.getmypid().'.gz';
        $handle = VmGzStream::gzopen($path, 'w9');
        if (false === $handle) {
            $this->markTestSkipped('VmGzStream::gzopen failed on this host');
        }
        $this->assertSame(5, VmGzStream::gzwrite($handle, 'hello'));
        VmGzStream::gzclose($handle);
        $handle = VmGzStream::gzopen($path, 'r');
        $this->assertNotFalse($handle);
        $this->assertSame('hello', VmGzStream::gzread($handle, 10));
        VmGzStream::gzclose($handle);
        @unlink($path);
    }

    public function testReadgzfileGzfileGzpassthruUsePureBackend(): void
    {
        if (!VmGzStreamNative::available()) {
            $this->markTestSkipped('VmZlib backend unavailable on this host');
        }

        $path = sys_get_temp_dir().'/phpc_gz_file_'.getmypid().'.gz';
        $handle = VmGzStream::gzopen($path, 'w9');
        if (false === $handle) {
            $this->markTestSkipped('VmGzStream::gzopen failed on this host');
        }
        VmGzStream::gzwrite($handle, "line1\nline2\n");
        VmGzStream::gzclose($handle);

        $lines = VmGzStream::gzfile($path);
        $this->assertIsArray($lines);
        $this->assertSame(["line1\n", "line2\n"], $lines);

        $bytes = VmGzStream::readgzfile($path);
        $this->assertSame(12, $bytes);

        $handle = VmGzStream::gzopen($path, 'r');
        $this->assertNotFalse($handle);
        $passthru = VmGzStream::gzpassthru($handle);
        VmGzStream::gzclose($handle);
        $this->assertSame(12, $passthru);
        @unlink($path);
    }

    public function testGzgetsLineRead(): void
    {
        if (!VmGzStreamNative::available()) {
            $this->markTestSkipped('VmZlib backend unavailable on this host');
        }

        $path = sys_get_temp_dir().'/phpc_gz_gets_'.getmypid().'.gz';
        $handle = VmGzStream::gzopen($path, 'w9');
        if (false === $handle) {
            $this->markTestSkipped('VmGzStream::gzopen failed on this host');
        }
        VmGzStream::gzwrite($handle, "line1\nline2\n");
        VmGzStream::gzclose($handle);
        $handle = VmGzStream::gzopen($path, 'r');
        $this->assertNotFalse($handle);
        $this->assertSame("line1\n", VmGzStream::gzgets($handle));
        $this->assertSame("line2\n", VmGzStream::gzgets($handle));
        $this->assertFalse(VmGzStream::gzgets($handle));
        VmGzStream::gzclose($handle);
        @unlink($path);
    }

    public function testGzopenCompressZlibDataUri(): void
    {
        if (!VmGzStreamNative::available()) {
            $this->markTestSkipped('VmZlib backend unavailable on this host');
        }

        $handle = VmGzStream::gzopen('compress.zlib://data:text/plain,hello', 'r');
        $this->assertNotFalse($handle);
        $this->assertSame('hello', VmGzStream::gzread($handle, 10));
        VmGzStream::gzclose($handle);

        $gz = \gzencode('world', 9);
        $this->assertNotFalse($gz);
        $b64 = \base64_encode($gz);
        $handle = VmGzStream::gzopen('compress.zlib://data:application/octet-stream;base64,'.$b64, 'r');
        $this->assertNotFalse($handle);
        $this->assertSame('world', VmGzStream::gzread($handle, 10));
        VmGzStream::gzclose($handle);
    }
}
