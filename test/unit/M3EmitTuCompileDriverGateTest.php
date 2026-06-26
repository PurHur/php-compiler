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
        $aot = (string) file_get_contents($root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');

        $this->assertStringContainsString('isM3EmitTuCompilerCompileChainLoweringName', $jit);
        $this->assertStringContainsString('m3EmitTuCompilerCompileChainLoweringSuffixes', $jit);
        $this->assertStringContainsString('isM3EmitTuRuntimeCompileDriverSpineLoweringName', $jit);
        $this->assertStringContainsString('shouldRealLowerInventoryArgvParseSpine', $jit);
        $this->assertStringContainsString('shouldRealLowerInventoryArgvParseSpine())', $jit);
        $this->assertStringContainsString('emitparseandcompilenulldiagnostic', $jit);
        $this->assertStringContainsString('shouldUseM4InventoryArgvNativeEmitRebuild', $jit);
        $this->assertStringContainsString('isLiteralIncludeDiscoveryRealLoweringMethod', $jit);
        $this->assertStringContainsString('isDeployRootRealLoweringMethod', $jit);
        $this->assertStringContainsString('isSourceBundlerRealLoweringMethod', $jit);
        $this->assertStringContainsString('shouldUseM3CompileDriverRealLowering()', $jit);
        $this->assertStringContainsString('Emit-helper binaries must init parse/compiler spine (#2633)', $emit);
        $this->assertStringContainsString('exitWithStatus', $emit);
        $this->assertStringContainsString('return true;', $emit);
        $this->assertStringContainsString('contentMatchOnly', $aot);
        $this->assertStringContainsString('COMPILER_LIB_SOURCE_PATH_NORM', $aot);
        $this->assertStringContainsString('COMPILER_LIB_SIDECAR_REL === $sidecarRel', $jit);
        $this->assertStringContainsString("memLimit = '8192M'", $jit);
        $this->assertStringContainsString('full-spine sidecar host-compile OOMs below 8GB (#8559)', $jit);
        $this->assertStringContainsString('StringFsDir::ensureLinked', $aot);
        $this->assertStringContainsString('__compiler_resolve_sidecar_source_path', $aot);
    }
}
