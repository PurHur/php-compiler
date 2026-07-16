<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Upload temp NestedJIT ABI bridges quarantined in ext/standard (#5346, #19723).
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

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUploadTempKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitUploadTempKernel', $source);
        $this->assertStringContainsString('__compiler_is_uploaded_file', $source);
        $this->assertStringContainsString('__compiler_move_uploaded_file', $source);
        $this->assertStringContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringContainsString('dirname(__DIR__, 2)', $source);
        $this->assertStringContainsString('UploadTempJitHelper', $source);
        $this->assertStringNotContainsString('dirname(__DIR__, 3)', $source);
        $this->assertLessThan(370, \substr_count($source, "\n") + 1);
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
