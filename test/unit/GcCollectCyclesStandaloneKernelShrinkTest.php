<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Standalone AOT GC cycle scan LLVM quarantined in ext/standard (#17015, #18630, #19596).
 *
 * PHP registry scan via GcCollectCyclesNativeScanJitHelper nested JIT breaks module verify;
 * standalone keeps LLVM cycle scan until array nested-JIT is safe.
 */
final class GcCollectCyclesStandaloneKernelShrinkTest extends TestCase
{
    public function testBuiltinStandaloneLlvmMovedToExtKernel(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesStandaloneLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitGcCollectCyclesStandaloneKernel.php');

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('JitGcCollectCyclesStandaloneKernel', $runtime);
        $this->assertStringNotContainsString('GcCollectCyclesStandaloneLlvm', $runtime);
        $this->assertStringContainsString('usesPhpRegistry', $runtime);
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $runtime);
        $this->assertStringNotContainsString('GcCollectCyclesStandaloneJitHelper', $runtime);
    }

    public function testKernelPresent(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGcCollectCyclesStandaloneKernel.php');
        $this->assertStringContainsString('namespace PHPCompiler\\ext\\standard;', $source);
        $this->assertStringContainsString('final class JitGcCollectCyclesStandaloneKernel', $source);
        $this->assertStringContainsString('implementCollectCyclesImpl', $source);
        $this->assertStringContainsString('ensureCycleScanInternals', $source);
    }

    public function testSpineBundleIncludesKernelNotBuiltinLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitGcCollectCyclesStandaloneKernel.php', $spine);
        $this->assertStringNotContainsString('GcCollectCyclesStandaloneLlvm.php', $spine);
    }

    public function testNativeScanJitHelperDocumentsFuturePhpPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcCollectCyclesNativeScanJitHelper.php');
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
    }
}
