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
        // M5 argv / gen-0 seed must real-lower even when SELFHOST_AOT was cleared (#26756).
        $realLowerPos = strpos($jit, 'function shouldUseM3CompileDriverRealLowering');
        $this->assertNotFalse($realLowerPos);
        $realLowerChunk = substr($jit, $realLowerPos, 700);
        $this->assertStringContainsString('shouldUseM5DriverHostCompile()', $realLowerChunk);
        $this->assertStringContainsString('AssignOp::optimize', $jit);
        $this->assertStringContainsString("'optimize' === \$methodLc", $jit);
        $this->assertStringContainsString('isM5ArgvResolveSidecarIdentityStubName', $jit);
        $this->assertStringContainsString('emitM5ArgvResolveSidecarIdentityStub', $jit);
        $compilePhp = (string) file_get_contents($root.'/bin/compile.php');
        $this->assertStringContainsString("str_ends_with(\$normalized, '/bin/compile.php')", $compilePhp);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $compilePhp);
        $this->assertStringContainsString('Emit-helper binaries must init parse/compiler spine (#2633)', $emit);
        $this->assertStringContainsString('exitWithStatus', $emit);
        $this->assertStringContainsString('return true;', $emit);
        $this->assertStringContainsString('contentMatchOnly', $aot);
        $this->assertStringContainsString('!$objectOnlySidecar', $aot);
        $this->assertStringContainsString('COMPILER_LIB_SOURCE_PATH_NORM', $aot);
        $this->assertStringContainsString('COMPILER_LIB_SIDECAR_REL === $sidecarRel', $jit);
        $this->assertStringContainsString("memLimit = '8192M'", $jit);
        $this->assertStringContainsString('full-spine sidecar host-compile OOMs below 8GB (#8559)', $jit);
        $this->assertStringContainsString('StringFsDir::ensureLinked', $aot);
        $this->assertStringContainsString('ensureSidecarCopyAbisForLink', $aot);
        $this->assertStringContainsString('ensureSidecarCopyAbisForLink($this->context)', $jit);
        $this->assertStringContainsString('__compiler_resolve_sidecar_source_path', $aot);
        $fnPos = strpos($aot, 'function emitStandaloneWriteCachedAot');
        $this->assertNotFalse($fnPos);
        $fnChunk = substr($aot, $fnPos, 1200);
        $this->assertStringNotContainsString('StringFsDir::ensureLinked', $fnChunk, '#21417: link ABIs before stub builder swap, not inside sidecar emit');
    }
}
