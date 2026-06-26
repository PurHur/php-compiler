<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmProcNiceNative;
use PHPCompiler\ext\standard\VmProcNicePure;
use PHPUnit\Framework\TestCase;

/** VmProcNicePure — proc_nice via /proc/self/autogroup without libc FFI (#12183). */
final class VmProcNiceRuntimeShrinkTest extends TestCase
{
    public function testVmProcNicePureDoesNotUseLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcNicePure.php');
        $this->assertStringContainsString('/proc/self/autogroup', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\proc_nice\\s*\\(/', $source);
    }

    public function testProcNiceVmReproWithFfiDisabled(): void
    {
        if (!VmProcNicePure::available()) {
            $this->markTestSkipped('/proc/self/autogroup unavailable');
        }

        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmProcNiceNative::available());
            $this->assertTrue(VmProcNiceNative::proc_nice(0));
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
