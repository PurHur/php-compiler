<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Standalone AOT GC cycle scan uses GcCollectCyclesStandaloneLlvm (#17015, #18630 regression fix).
 *
 * PHP registry scan via GcCollectCyclesNativeScanJitHelper nested JIT breaks module verify;
 * standalone keeps LLVM cycle scan until array nested-JIT is safe.
 */
final class GcCollectCyclesStandaloneLlvmShrinkTest extends TestCase
{
    public function testGcCollectCyclesRuntimeUsesStandaloneLlvmForCollect(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('GcCollectCyclesStandaloneLlvm', $source);
        $this->assertStringContainsString('usesPhpRegistry', $source);
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
        $this->assertStringNotContainsString('GcCollectCyclesStandaloneJitHelper', $source);
    }

    public function testStandaloneLlvmPresent(): void
    {
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesStandaloneLlvm.php');
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesStandaloneLlvm.php');
        $this->assertStringContainsString('implementCollectCyclesImpl', $source);
        $this->assertStringContainsString('ensureCycleScanInternals', $source);
    }

    public function testNativeScanJitHelperDocumentsFuturePhpPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GcCollectCyclesNativeScanJitHelper.php');
        $this->assertStringContainsString('GcCollectCyclesRegistryJitHelper', $source);
    }
}
