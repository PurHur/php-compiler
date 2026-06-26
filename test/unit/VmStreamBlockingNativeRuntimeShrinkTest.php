<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStreamBlockingNative;
use PHPUnit\Framework\TestCase;

/** VmStreamBlockingPure / VmStreamBlockingNative — no libc fcntl FFI (#12251). */
final class VmStreamBlockingNativeRuntimeShrinkTest extends TestCase
{
    public function testVmStreamBlockingNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamBlockingNative.php');
        $this->assertStringContainsString('VmStreamBlockingPure::', $source);
        $this->assertStringContainsString('VmStreamBlockingPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testVmStreamBlockingPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamBlockingPure.php');
        $this->assertStringContainsString('VmPhpFdStream::setBlockingOnFd', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
    }

    public function testVmStreamMetaDoesNotCallHostStreamSetBlocking(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStreamMeta.php');
        $this->assertStringNotContainsString('\\stream_set_blocking(', $source);
    }

    public function testVmFsStreamSetBlockingRoutesFdStreams(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmStreamBlockingNative::setBlocking', $source);
        $this->assertStringContainsString('socketFdForHandle', $source);
    }
}
