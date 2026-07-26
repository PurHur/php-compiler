<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ProgressNote NestedJIT via JitVmHelperLink::ensureCompiled (#23311 / peer #23301).
 */
final class ProgressNoteRuntimeShrinkTest extends TestCase
{
    public function testBuiltinProgressNoteRuntimeIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitProgressNoteKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntime.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProgressNoteRuntime.php');
        $this->assertStringContainsString('JitProgressNoteKernel', $orchestrator);
        $this->assertStringContainsString('JitProgressNoteKernel::ensureLinked', $orchestrator);
        $this->assertStringContainsString('JitProgressNoteKernel::ensureStandaloneBodies', $orchestrator);
        $this->assertStringContainsString('JitProgressNoteKernel::emitCall', $orchestrator);
        $this->assertStringContainsString('JitProgressNoteKernel::implement', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $orchestrator);
        $this->assertStringNotContainsString('implementNoteBridge', $orchestrator);
        $this->assertStringNotContainsString('phpc_segv_handler', $orchestrator);
        $this->assertLessThan(50, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitProgressNoteKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitProgressNoteKernel', $source);
        $this->assertStringContainsString('__phpc_progress_note', $source);
        $this->assertStringContainsString('ProgressJitHelper', $source);
        $this->assertStringContainsString('phpc_segv_handler', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(600, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitProgressNoteKernel.php', $spine);
        $this->assertStringContainsString('ProgressNoteRuntime.php', $spine);
        $kernelPos = strpos($spine, 'JitProgressNoteKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/ProgressNoteRuntime.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }
}
