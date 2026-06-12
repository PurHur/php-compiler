<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmGzStream;
use PHPCompiler\ext\standard\VmGzStreamNative;
use PHPUnit\Framework\TestCase;

/** VmGzStream libz FFI without host gz* delegation (#6168, #8220). */
final class VmGzStreamRuntimeShrinkTest extends TestCase
{
    public function testVmGzStreamUsesNativeFfiWithoutHostDelegation(): void
    {
        $stream = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGzStream.php');
        $this->assertStringContainsString('VmGzStreamNative', $stream);
        $this->assertStringNotContainsString('function_exists(', $stream);
        $this->assertStringNotContainsString('\\gzopen(', $stream);
        $this->assertStringNotContainsString('\\gzwrite(', $stream);
        $this->assertStringNotContainsString('\\gzread(', $stream);
        $this->assertStringNotContainsString('\\gzclose(', $stream);

        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGzStreamNative.php');
        $this->assertStringContainsString('$ffi->gzopen', $native);
        $this->assertStringNotContainsString('\\gzopen(', $native);
    }

    public function testLibzGzStreamFfiAvailableOnHarness(): void
    {
        if (!VmGzStreamNative::available()) {
            $this->markTestSkipped('libz gz* FFI unavailable on this host');
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

    public function testReadgzfileGzfileGzpassthruUseNativeFfi(): void
    {
        if (!VmGzStreamNative::available()) {
            $this->markTestSkipped('libz gz* FFI unavailable on this host');
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
}
