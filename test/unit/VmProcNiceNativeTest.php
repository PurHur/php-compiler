<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmProcNiceNative;
use PHPCompiler\ext\standard\VmProcess;
use PHPUnit\Framework\TestCase;

/** VmProcNiceNative libc path without host \\proc_nice() delegation (#7862). */
final class VmProcNiceNativeTest extends TestCase
{
    public function testVmProcessDoesNotReferenceHostProcNice(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcess.php');
        $this->assertStringContainsString('VmProcNiceNative::proc_nice', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_nice\\s*\\(/', $source);
        $this->assertStringNotContainsString('function_exists(\'proc_nice\')', $source);
    }

    public function testNativeDefinesLibcNiceFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcNiceNative.php');
        $this->assertStringContainsString('int nice(int inc)', $source);
        $this->assertStringContainsString('$ffi->nice', $source);
        $this->assertStringContainsString('__errno_location', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_nice\\s*\\(/', $source);
    }

    public function testProcNiceReturnsBoolWhenLibcAvailable(): void
    {
        if (!VmProcNiceNative::available()) {
            $this->markTestSkipped('libc FFI nice unavailable');
        }

        $this->assertIsBool(VmProcNiceNative::proc_nice(0));
        $this->assertIsBool(VmProcess::proc_nice(0));
    }
}
