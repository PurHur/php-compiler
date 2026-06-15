<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmPhpcRunCommandNative;
use PHPCompiler\ext\standard\VmPopenNative;
use PHPCompiler\ext\standard\VmProcessExecCaptureNative;
use PHPUnit\Framework\TestCase;

/** phpc_run_command VM must not call host proc_open (#8633, #8648). */
final class VmPhpcRunCommandRuntimeShrinkTest extends TestCase
{
    public function testPhpcRunCommandBuiltinDoesNotCallHostProcOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/phpc_run_command.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_open\\(/', $source);
        $this->assertStringContainsString('VmPhpcRunCommandNative::run', $source);
    }

    public function testVmPhpcRunCommandNativeUsesPopenWhenEnvNull(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPhpcRunCommandNative.php');
        $this->assertStringContainsString('VmPopenNative::open', $source);
        $this->assertStringContainsString('VmPopenNative::pclose', $source);
        $this->assertStringContainsString('VmProcessExecCaptureNative::runWithEnv', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_open\\(/', $source);
    }

    public function testVmProcessExecCaptureNativeUsesLibcForkNotHostProcOpen(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmProcessExecCaptureNative.php');
        $this->assertStringContainsString('fork', $source);
        $this->assertStringContainsString('setenv', $source);
        $this->assertDoesNotMatchRegularExpression('/\\\\proc_open\\(/', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/VmPhpcRunCommandHost.php');
    }

    public function testNullEnvCaptureWhenFfiAvailable(): void
    {
        if (!VmPopenNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $result = VmPhpcRunCommandNative::run('echo phpc-run-smoke');
        $this->assertIsArray($result);
        $this->assertSame(0, $result['code']);
        $this->assertSame("phpc-run-smoke\n", $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testEnvCaptureWhenFfiAvailable(): void
    {
        if (!VmProcessExecCaptureNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $result = VmPhpcRunCommandNative::run('echo "$PHPC_RUN_ENV_SMOKE"', ['PHPC_RUN_ENV_SMOKE' => 'env-ok']);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['code']);
        $this->assertSame("env-ok\n", $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }
}
