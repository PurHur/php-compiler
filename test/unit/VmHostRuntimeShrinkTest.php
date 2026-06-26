<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmHost;
use PHPCompiler\ext\standard\VmHostPure;
use PHPUnit\Framework\TestCase;

/** VmHost — gethostname without libc FFI (#12169). */
final class VmHostRuntimeShrinkTest extends TestCase
{
    public function testVmHostDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHost.php');
        $this->assertStringContainsString('VmHostPure::gethostname', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->gethostname', $source);
    }

    public function testVmHostPureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmHostPure.php');
        $this->assertStringContainsString('/proc/sys/kernel/hostname', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\gethostname\\s*\\(/', $source);
    }

    public function testGethostnameWorksWithFfiDisabled(): void
    {
        if (!VmHostPure::available()) {
            $this->markTestSkipped('hostname sources unavailable on this host');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmHost::available());
            $host = VmHost::gethostname();
            $this->assertIsString($host);
            $this->assertNotSame('', $host);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
