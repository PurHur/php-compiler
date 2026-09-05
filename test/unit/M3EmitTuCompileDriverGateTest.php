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

        $spineNativeCfg = (string) file_get_contents($root.'/lib/JIT/Concern/M3EmitTuSpineNativeTryAndCfgParamTypes.php');
        $this->assertStringContainsString('isM3EmitTuCompilerCompileChainLoweringName', $spineNativeCfg);
        $this->assertStringContainsString('m3EmitTuCompilerCompileChainLoweringSuffixes', $spineNativeCfg);
        $this->assertStringContainsString('isM3EmitTuRuntimeCompileDriverSpineLoweringName', $spineNativeCfg);
        $driverPolicy = (string) file_get_contents($root.'/lib/JIT/Concern/M3M4M5CompileDriverEmitPolicy.php');
        $this->assertStringContainsString('shouldRealLowerInventoryArgvParseSpine', $driverPolicy);
        $this->assertStringContainsString('shouldRealLowerInventoryArgvParseSpine())', $driverPolicy);
        $this->assertStringContainsString('emitparseandcompilenulldiagnostic', $driverPolicy);
        $this->assertStringContainsString('shouldUseM4InventoryArgvNativeEmitRebuild', $driverPolicy);
        $hotPath = (string) file_get_contents($root.'/lib/JIT/Concern/SkippedHotPathAndRealLoweringNames.php');
        $this->assertStringContainsString('isLiteralIncludeDiscoveryRealLoweringMethod', $hotPath);
        $this->assertStringContainsString('isDeployRootRealLoweringMethod', $hotPath);
        $this->assertStringContainsString('isSourceBundlerRealLoweringMethod', $hotPath);
        $this->assertStringContainsString('shouldUseM3CompileDriverRealLowering()', $driverPolicy);
        // M5 argv / gen-0 seed: C-floor initParsePipeline avoids NestedJIT hang (#26756).
        $this->assertFileExists($root.'/lib/JIT/RuntimeInitParsePipeline.php');
        $vmSmoke = (string) file_get_contents($root.'/lib/JIT/Concern/VmSmokeAndRuntimeM3NativeStubs.php');
        $this->assertStringContainsString('RuntimeInitParsePipeline::emit', $vmSmoke);
        $this->assertStringContainsString('require_once __DIR__.\'/JIT/RuntimeInitParsePipeline.php\'', $jit);
        $allowPos = strpos($driverPolicy, 'function isM3CompileDriverRealLoweringName');
        $this->assertNotFalse($allowPos);
        $allowChunk = substr($driverPolicy, $allowPos, 4500);
        $this->assertStringContainsString('shouldUseM5DriverHostCompile()', $allowChunk);
        $this->assertStringContainsString('\\runtime::initparsepipeline', $allowChunk);
        $floor = (string) file_get_contents($root.'/lib/JIT/RuntimeInitParsePipeline.php');
        $this->assertStringContainsString("PHPCfg\\\\Parser", $floor);
        $this->assertStringContainsString('allocConstructed', $floor);
        $compilePhp = (string) file_get_contents($root.'/bin/compile.php');
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $compilePhp);
        $this->assertStringContainsString('Emit-helper binaries must init parse/compiler spine (#2633)', $emit);
        $this->assertStringContainsString('exitWithStatus', $emit);
        $this->assertStringContainsString('return true;', $emit);
        $this->assertStringContainsString('contentMatchOnly', $aot);
        $this->assertStringContainsString('!$objectOnlySidecar', $aot);
        $this->assertStringContainsString('COMPILER_LIB_SOURCE_PATH_NORM', $aot);
        $sidecar = (string) file_get_contents($root.'/lib/JIT/Concern/M3EmitTuSidecarLinktime.php');
        $this->assertStringContainsString('COMPILER_LIB_SIDECAR_REL === $sidecarRel', $sidecar);
        $this->assertStringContainsString("memLimit = '8192M'", $sidecar);
        $this->assertStringContainsString('full-spine sidecar host-compile OOMs below 8GB (#8559)', $sidecar);
        $this->assertStringContainsString('StringFsDir::ensureLinked', $aot);
        $this->assertStringContainsString('ensureSidecarCopyAbisForLink', $aot);
        $spineStub = (string) file_get_contents($root.'/lib/JIT/Concern/M3EmitTuRuntimeSpineStubNative.php');
        $this->assertStringContainsString('ensureSidecarCopyAbisForLink($this->context)', $spineStub);
        $this->assertStringContainsString('__compiler_resolve_sidecar_source_path', $aot);
        $fnPos = strpos($aot, 'function emitStandaloneWriteCachedAot');
        $this->assertNotFalse($fnPos);
        $fnChunk = substr($aot, $fnPos, 1200);
        $this->assertStringNotContainsString('StringFsDir::ensureLinked', $fnChunk, '#21417: link ABIs before stub builder swap, not inside sidecar emit');
    }
}
