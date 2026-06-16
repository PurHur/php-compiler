<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmTmpfileNative;
use PHPCompiler\ext\standard\VmTmpfilePure;
use PHPUnit\Framework\TestCase;

/** VmTmpfilePure — tmpfile() without libc FFI (#9033). */
final class VmTmpfilePureRuntimeShrinkTest extends TestCase
{
    public function testVmTmpfileNativeDelegatesToPureOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmTmpfileNative.php');
        $this->assertStringContainsString('VmTmpfilePure::open', $source);
        $this->assertStringContainsString('VmTmpfilePure::available', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->tmpfile', $source);
        $this->assertStringNotContainsString('$ffi->dup', $source);
    }

    public function testVmTmpfilePureUsesPhpTempStreamNotHostTmpfile(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmTmpfilePure.php');
        $this->assertStringContainsString('VmPhpMemoryStream::open', $source);
        $this->assertStringContainsString('php://temp', $source);
        $this->assertStringNotContainsString('\\tmpfile(', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testTmpfileReadWriteWithFfiDisabled(): void
    {
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmTmpfileNative::available());
            $handle = VmFs::tmpfile();
            $this->assertNotFalse($handle);
            $this->assertSame(4, VmFs::fwrite($handle, 'data'));
            $this->assertTrue(VmFs::rewind($handle));
            $this->assertSame('data', VmFs::streamGetContents($handle));
            $this->assertTrue(VmFs::fclose($handle));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testPureOpenReturnsStreamHandle(): void
    {
        $handle = VmTmpfilePure::open();
        $this->assertNotFalse($handle);
        $this->assertSame(4, VmFs::fwrite($handle, 'pure'));
        $this->assertTrue(VmFs::fclose($handle));
    }
}
