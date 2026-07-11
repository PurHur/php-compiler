<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM php://stdio wrappers use VmFsStdioPure, not libc dup FFI (#4648, #12252). */
final class VmFsStdioRuntimeShrinkTest extends TestCase
{
    public function testVmFsFopenRoutesStdioThroughVmFsStdio(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmFsStdio::isStdioUri', $source);
        $this->assertStringContainsString('VmFsStdio::open', $source);
    }

    public function testVmFsStdioNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsStdioNative.php');
        $this->assertStringContainsString('VmFsStdioPure::', $native);
        $this->assertStringNotContainsString('FFI::cdef', $native);
        $this->assertStringNotContainsString('\\FFI', $native);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsStdioPure.php');
        $this->assertStringContainsString('fopen', $pure);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testVmFsStdioUsesNativeDupOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsStdio.php');
        $this->assertStringContainsString('VmFsStdioNative::openDupFd', $source);
        $this->assertDoesNotMatchRegularExpression('/@fopen\\s*\\(/', $source);
    }

    public function testStreamIoJitHelperOpensStdioViaVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamIoJitHelper.php');
        $this->assertStringContainsString('VmFs::fopen', $source);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFsStdioPure.php');
        $this->assertStringContainsString('php://stdin', $pure);
    }
}
