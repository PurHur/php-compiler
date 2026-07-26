<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UploadTemp NestedJIT via JitVmHelperLink::ensureCompiled (#23301 / peer #23211).
 */
final class UploadTempKernelShrinkTest extends TestCase
{
    public function testBuiltinUploadTempJitIsThinOrchestrator(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitUploadTempKernel.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/UploadTempJit.php');

        $orchestrator = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UploadTempJit.php');
        $this->assertStringContainsString('JitUploadTempKernel', $orchestrator);
        $this->assertStringContainsString('JitUploadTempKernel::implement', $orchestrator);
        $this->assertStringNotContainsString('NestedJitCompileScope', $orchestrator);
        $this->assertStringNotContainsString('__compiler_is_uploaded_file', $orchestrator);
        $this->assertLessThan(30, \substr_count($orchestrator, "\n") + 1);
    }

    public function testKernelUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUploadTempKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitUploadTempKernel', $source);
        $this->assertStringContainsString('__compiler_is_uploaded_file', $source);
        $this->assertStringContainsString('__compiler_move_uploaded_file', $source);
        $this->assertStringContainsString('UploadTempJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(340, \substr_count($source, "\n") + 1);
    }

    public function testSpineBundleIncludesKernelAndOrchestrator(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitUploadTempKernel.php', $spine);
        $this->assertStringContainsString('UploadTempJit.php', $spine);
        $kernelPos = strpos($spine, 'JitUploadTempKernel.php');
        $orchPos = strpos($spine, 'lib/JIT/Builtin/UploadTempJit.php');
        $this->assertNotFalse($kernelPos);
        $this->assertNotFalse($orchPos);
        $this->assertLessThan($orchPos, $kernelPos, 'kernel must load before thin orchestrator');
    }
}
