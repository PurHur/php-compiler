<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStatNative;
use PHPCompiler\ext\standard\VmStatPure;
use PHPUnit\Framework\TestCase;

/** VmStatPure — stat()/lstat()/fstat/realpath without libc stat(2) FFI (#8903, #12265). */
final class VmStatPureRuntimeShrinkTest extends TestCase
{
    public function testVmStatNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStatNative.php');
        $this->assertStringContainsString('VmStatPure::stat', $source);
        $this->assertStringContainsString('VmStatPure::lstat', $source);
        $this->assertStringContainsString('VmStatPure::fstatFd', $source);
        $this->assertStringContainsString('VmStatPure::realpath', $source);
        $this->assertStringContainsString('VmStatPure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->stat/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->realpath/', $source);
    }

    public function testVmStatPureDoesNotUseStatFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStatPure.php');
        $this->assertStringContainsString('\\stat(', $source);
        $this->assertStringContainsString('\\lstat(', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->stat/', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->lstat/', $source);
    }

    public function testStatNoFfiReturnsZendKeyedArray(): void
    {
        if (!VmStatPure::available()) {
            $this->markTestSkipped('host stat() unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmStatNative::available());
            $stat = VmStatNative::stat(__FILE__);
            $this->assertIsArray($stat);
            $this->assertArrayHasKey('mtime', $stat);
            $this->assertArrayHasKey(9, $stat);
            $this->assertSame($stat['mtime'], $stat[9]);
            $lstat = VmStatNative::lstat(__FILE__);
            $this->assertIsArray($lstat);
            $this->assertArrayHasKey('mode', $lstat);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testStatPureReturnsFalseForMissingPath(): void
    {
        if (!VmStatPure::available()) {
            $this->markTestSkipped('host stat() unavailable');
        }
        $this->assertFalse(VmStatPure::stat('/no/such/phpc-stat-pure-'.bin2hex(random_bytes(4))));
    }
}
