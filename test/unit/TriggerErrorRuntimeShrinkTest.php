<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TriggerError NestedJIT ABI bridges quarantined in ext/standard (#9293, #19864).
 */
final class TriggerErrorRuntimeShrinkTest extends TestCase
{
    public function testStringTriggerErrorJitUsesTriggerErrorJitHelperNotLlvmBodies(): void
    {
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertStringContainsString('TriggerErrorJitHelper', $kernel);
        $this->assertStringNotContainsString('emitStderrPrintCliError', $kernel);
        $this->assertStringNotContainsString('selectErrorPrefix', $kernel);
        $this->assertStringNotContainsString('recordAndMaybePrint', $kernel);
        $this->assertFileExists(__DIR__.'/../../ext/standard/TriggerErrorJitHelper.php');
    }

    public function testBuiltinStringTriggerErrorJitIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/StringTriggerErrorJit.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTriggerErrorJit.php');
        $this->assertStringContainsString('JitTriggerErrorKernel', $orchestrator);
        $this->assertStringContainsString('JitTriggerErrorKernel::implement', $orchestrator);
        $this->assertStringContainsString('JitTriggerErrorKernel::stderrFilePtr', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('implementTriggerErrorBridge', $orchestrator);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $orchestrator);
        $this->assertLessThan(40, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTriggerErrorKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitTriggerErrorKernel', $source);
        $this->assertStringContainsString('__compiler_trigger_error', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(500, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitTriggerErrorKernel.php', $spine);
        $this->assertStringContainsString('StringTriggerErrorJit.php', $spine);
        $kernelPos = strpos($spine, 'JitTriggerErrorKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/StringTriggerErrorJit.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }
}
