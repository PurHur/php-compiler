<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ProcessJitHelper;
use PHPCompiler\ext\standard\VmEscapeshell;
use PHPCompiler\ext\standard\VmShellExecNative;
use PHPUnit\Framework\TestCase;

/** ProcessRuntime routes standalone + embed through ProcessJitHelper PHP not libc LLVM (#9337, #12950). */
final class ProcessRuntimeShrinkTest extends TestCase
{
    public function testProcessRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessRuntime.php');
        $this->assertStringContainsString('ProcessJitHelper', $runtime);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit::shouldDefer', $runtime);
        $this->assertStringContainsString('ProcessExecCaptureLlvm::implementBridge', $runtime);
        $this->assertStringContainsString('ProcessShellExecLibc::implement', $runtime);
        $this->assertStringNotContainsString('ProcessStandaloneLlvm', $runtime);
        $this->assertStringNotContainsString('emitShellExec', $runtime);
        $this->assertStringNotContainsString('emitEscapeshellarg', $runtime);
        $this->assertStringNotContainsString('ensureLibc', $runtime);
        $this->assertLessThan(470, \substr_count($runtime, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/ProcessStandaloneLlvm.php');
    }

    public function testProcessJitHelperDelegatesToVmSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcessJitHelper.php');
        $this->assertStringContainsString('VmShellExecNative::shellExec', $source);
        $this->assertStringContainsString('VmEscapeshell::escapeshellarg', $source);
        $this->assertStringContainsString('VmEscapeshell::escapeshellcmd', $source);

        $captureSource = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcessExecCaptureNativeJitHelper.php');
        $this->assertStringContainsString('VmShellExecNative::shellExec', $captureSource);
        $this->assertStringContainsString('phpc_native_ht_set_string_at', $captureSource);

        $phpcSource = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcessPhpcRunCommandJitHelper.php');
        $this->assertStringContainsString('VmPhpcRunCommandNative::run', $phpcSource);
    }

    public function testEscapeshellargArgvMatchesVm(): void
    {
        $input = "it's fine";
        $this->assertSame(
            VmEscapeshell::escapeshellarg($input),
            ProcessJitHelper::escapeshellargArgv($input)
        );
    }

    public function testShellExecArgvWhenPopenAvailable(): void
    {
        if (!\PHPCompiler\ext\standard\VmPopenNative::available()) {
            $this->markTestSkipped('popen unavailable on this host');
        }
        $result = ProcessJitHelper::shellExecArgv('echo hi');
        $this->assertIsString($result);
        $this->assertSame(
            VmShellExecNative::shellExec('echo hi'),
            $result
        );
    }
}
