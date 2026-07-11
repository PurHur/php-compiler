<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmProcNiceNative;
use PHPCompiler\ext\standard\VmProcNicePure;
use PHPCompiler\ext\standard\VmProcess;
use PHPUnit\Framework\TestCase;

/** VmProcNiceNative — proc_nice without libc nice FFI (#12183). */
final class VmProcNiceNativeTest extends TestCase
{
    public function testVmProcessDoesNotReferenceHostProcNice(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmProcNiceNative::proc_nice', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_nice\\s*\\(/', $source);
        $this->assertStringNotContainsString('function_exists(\'proc_nice\')', $source);
    }

    public function testVmProcNiceNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcNiceNative.php');
        $this->assertStringContainsString('VmProcNicePure::proc_nice', $source);
        $this->assertStringContainsString('VmProcNicePure::available()', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertDoesNotMatchRegularExpression('/\$ffi->nice/', $source);
    }

    public function testVmProcNicePureUsesAutogroupProcfs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcNicePure.php');
        $this->assertStringContainsString('/proc/self/autogroup', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testProcNiceReturnsBoolWhenPureAvailable(): void
    {
        if (!VmProcNiceNative::available()) {
            $this->markTestSkipped('/proc/self/autogroup unavailable');
        }

        $this->assertIsBool(VmProcNiceNative::proc_nice(0));
        $this->assertIsBool(VmProcess::proc_nice(0));
    }

    public function testProcNiceWorksWithFfiDisabledOnLinux(): void
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
