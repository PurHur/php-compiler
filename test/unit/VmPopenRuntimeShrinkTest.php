<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPCompiler\ext\standard\VmPopenNative;
use PHPCompiler\ext\standard\VmShellExecNative;
use PHPUnit\Framework\TestCase;

/** VM popen/pclose/shell_exec must not delegate to host PHP (#8250, #6211, #5348). */
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

    public function testVmPopenNativeUsesLibcPopen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPopenNative.php');
        $this->assertStringContainsString('$ffi->popen', $source);
        $this->assertStringContainsString('$ffi->pclose', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\popen\\(/', $source);
    }

    public function testPopenRoundTripWhenFfiAvailable(): void
    {
        if (!VmPopenNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $opened = VmPopenNative::open('echo hello', 'r');
        $this->assertIsArray($opened);
        $this->assertIsInt($opened['handle']);
        $output = VmPhpFdStream::streamGetContents($opened['handle']);
        VmPhpFdStream::close($opened['handle']);
        $status = VmPopenNative::pclose($opened['file']);
        $this->assertSame("hello\n", $output);
        $this->assertSame(0, $status);
    }

    public function testShellExecCapturesOutputWhenFfiAvailable(): void
    {
        if (!VmPopenNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $result = VmShellExecNative::shellExec('echo hi');
        $this->assertSame("hi\n", $result);
    }
}
