<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ProcessJitHelper;
use PHPCompiler\ext\standard\VmEscapeshell;
use PHPCompiler\ext\standard\VmShellExecNative;
use PHPUnit\Framework\TestCase;

/** ProcessRuntime embed routes through ProcessJitHelper PHP not libc LLVM (#9337). */
final class ProcessRuntimeShrinkTest extends TestCase
{
    public function testProcessRuntimeIsThinRouter(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessRuntime.php');
        $this->assertStringContainsString('ProcessStandaloneLlvm::implement', $runtime);
        $this->assertStringContainsString('ProcessJitHelper', $runtime);
        $this->assertStringNotContainsString('emitShellExec', $runtime);
        $this->assertStringNotContainsString('emitEscapeshellarg', $runtime);
        $this->assertStringNotContainsString('ensureLibc', $runtime);
        $this->assertLessThan(290, \substr_count($runtime, "\n") + 1);
    }

    public function testStandaloneLlvmQuarantinesLibcProcessHelpers(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessStandaloneLlvm.php');
        $this->assertStringContainsString('emitShellExec', $llvm);
        $this->assertStringContainsString('popen', $llvm);
        $this->assertStringNotContainsString('NestedJitCompileScope', $llvm);
    }

    public function testProcessJitHelperDelegatesToVmSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcessJitHelper.php');
        $this->assertStringContainsString('VmShellExecNative::shellExec', $source);
        $this->assertStringContainsString('VmEscapeshell::escapeshellarg', $source);
        $this->assertStringContainsString('VmEscapeshell::escapeshellcmd', $source);
        $this->assertStringContainsString('VmPhpcRunCommandNative::run', $source);
        $this->assertStringContainsString('VmPopenNative::open', $source);
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
