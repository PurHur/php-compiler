<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmExecNative;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPCompiler\ext\standard\VmPopenNative;
use PHPCompiler\ext\standard\VmPopenPure;
use PHPCompiler\ext\standard\VmShellExecNative;
use PHPUnit\Framework\TestCase;

/** VM popen/pclose/shell_exec/exec must not delegate to host PHP wrappers (#8250, #6211, #5348, #8951). */
final class VmPopenRuntimeShrinkTest extends TestCase
{
    public function testVmFsPopenRoutesThroughVmPopenNativeFirst(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFs.php');
        $this->assertStringContainsString('VmPopenNative::available()', $source);
        $this->assertStringContainsString('VmPopenNative::open', $source);
        $this->assertStringContainsString('VmPopenNative::pclose', $source);
        $this->assertStringContainsString('$popenNativeFiles', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\popen\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\pclose\\(/', $source);
    }

    public function testShellExecBuiltinDoesNotCallHostShellExec(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/shell_exec.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\shell_exec\\(/', $source);
        $this->assertStringContainsString('VmShellExecNative::shellExec', $source);
    }

    public function testVmPopenNativeDelegatesToPureWhenFfiDisabled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPopenNative.php');
        $this->assertStringContainsString('VmPopenPure::', $source);
        $this->assertStringContainsString('VmPopenPure::available()', $source);
        $this->assertStringContainsString('$ffi->popen', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\popen\\(/', $source);
    }

    public function testVmPopenPureUsesProcOpenNotLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPopenPure.php');
        $this->assertStringContainsString('proc_open', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('popen(const char', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\popen\\(/', $source);
    }

    public function testPopenRoundTripWhenFfiAvailable(): void
    {
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI');
        try {
            $opened = VmPopenNative::open('echo hello', 'r');
            $this->assertIsArray($opened);
            $this->assertIsInt($opened['handle']);
            $output = VmFs::streamGetContents($opened['handle']);
            VmFs::fclose($opened['handle']);
            $status = VmPopenNative::pclose($opened['file']);
            $this->assertSame("hello\n", $output);
            $this->assertSame(0, $status);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testPopenRoundTripWhenFfiDisabled(): void
    {
        if (!VmPopenPure::available()) {
            $this->markTestSkipped('host proc_open unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmPopenNative::available());

            $opened = VmPopenNative::open('echo hello', 'r');
            $this->assertIsArray($opened);
            $this->assertIsInt($opened['handle']);
            $this->assertIsInt($opened['file']);
            $output = VmFs::streamGetContents($opened['handle']);
            VmFs::fclose($opened['handle']);
            $status = VmPopenNative::pclose($opened['file']);
            $this->assertSame("hello\n", $output);
            $this->assertSame(0, $status);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testShellExecCapturesOutputWhenFfiDisabled(): void
    {
        if (!VmPopenPure::available()) {
            $this->markTestSkipped('host proc_open unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $result = VmShellExecNative::shellExec('echo hi');
            $this->assertSame("hi\n", $result);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testExecCapturesOutputWhenFfiDisabled(): void
    {
        if (!VmPopenPure::available()) {
            $this->markTestSkipped('host proc_open unavailable');
        }

        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $result = VmExecNative::run('printf "line1\nline2\n"');
            $this->assertIsArray($result);
            $this->assertSame(['line1', 'line2'], $result['lines']);
            $this->assertSame(0, $result['status']);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }
}
