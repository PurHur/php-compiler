<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class M3EmitTuCompileDriverGateTest extends TestCase
{
    public function testEmitTuCompileDriverGateAndRuntimeInitPresentInJit(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/lib/JIT.php');
        $emit = (string) file_get_contents($root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');

        $this->assertStringContainsString('isM3EmitTuCompilerCompileChainLoweringName', $jit);
        $this->assertStringContainsString('m3EmitTuCompilerCompileChainLoweringSuffixes', $jit);
        $this->assertStringContainsString('isM3EmitTuRuntimeCompileDriverSpineLoweringName', $jit);
        $this->assertStringContainsString('isLiteralIncludeDiscoveryRealLoweringMethod', $jit);
        $this->assertStringContainsString('isDeployRootRealLoweringMethod', $jit);
        $this->assertStringContainsString('isSourceBundlerRealLoweringMethod', $jit);
        $this->assertStringContainsString('shouldUseM3CompileDriverRealLowering()', $jit);
        $this->assertStringContainsString('Emit-helper binaries must init parse/compiler spine (#2633)', $emit);
        $this->assertStringContainsString('exitWithStatus', $emit);
        $this->assertStringContainsString('return true;', $emit);
    }
}
