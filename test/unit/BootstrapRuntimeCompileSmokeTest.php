<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapRuntimeCompileSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testRuntimeCompileSmokeProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_RUNTIME_COMPILE_SMOKE_STRICT=1', $source);
        $this->assertStringContainsString('emit_path=', $source);
        $this->assertStringContainsString('runtime_compile_smoke bundle OK', $source);
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $source);
        $this->assertStringContainsString('inventory compile_driver', $source);
    }

    public function testRuntimeCompileDriverLintPasses(): void
    {
        $driver = self::$root.'/test/selfhost/runtime_compile_smoke/compile_driver.php';
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($driver).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testCompilerUnitProbeCompileDriverLintPasses(): void
    {
        $driver = self::$root.'/test/selfhost/compiler_unit_probe/compile_driver.php';
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($driver).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testRuntimeCompileSmokeProbePartialGreenWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 runtime compile-smoke probe test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-runtime-compile-smoke: OK', $out);
        $this->assertStringContainsString('emit_path=native', $out);
        $this->assertStringContainsString('runtime-trivial-aot stdout: 1', $out);
        $this->assertTrue(is_executable(self::$root.'/build/runtime-trivial-aot'));
    }

    public function testRuntimeCompileSmokeBundleLintPasses(): void
    {
        $bundle = self::$root.'/test/selfhost/runtime_compile_smoke/main.php';
        $this->assertFileExists($bundle);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($bundle).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testRuntimeCompileSmokeProbeDefaultsRealLoweringWhenLinkCompileDriver(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1', $source);
        $this->assertStringContainsString('BOOTSTRAP_M3_RUNTIME_COMPILE:=1', $source);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1', $source);
    }

    public function testRuntimeCompileSmokeProbeScriptWiresNativeCompileDriver(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh';
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER', $source);
        $this->assertStringContainsString('runtime_compile_smoke/compile_driver.php', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER=1', $source);
        $this->assertStringContainsString('runtime_compile_smoke_m3_emit', $source);
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('isBootstrapM3RuntimeEmitBridgeName', $jit);
        $this->assertStringContainsString('VariableTypeMapNative', $jit);
        $this->assertStringContainsString('runtime_compile_smoke_m3_emit', $jit);
        $this->assertStringContainsString('compileRuntimeParseAndCompileM3Native', $jit);
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('declareRuntimeParseAndCompileNative', $emit);
    }

    /** Issue #3032: runtime probe links inventory compile_driver only. */
    public function testCompilePhpPreservesSelfhostAotForRuntimeInventoryEmitDriver(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('runtime_compile_smoke/compile_driver.php', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1', $compile);
        $this->assertStringNotContainsString('m3_emit_native_entry', $compile);
        $probe = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER=1', $probe);
    }

    public function testRuntimeCompileSmokeProbeSetsEmitHelperLinkEnv(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $source);
    }

    /** Issue #2879: inventory compile_driver without *_m3_emit_native_entry.php. */
    public function testRuntimeCompileSmokeProbeDocumentsInventoryEmitDriverOptIn(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $script);
        $this->assertStringContainsString('inventory compile_driver', $script);
        $this->assertFileExists(self::$root.'/test/selfhost/runtime_compile_smoke/compile_driver.php');
    }

    public function testM3EmitTuUsesMinimalRuntimeShellAlloc(): void
    {
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('emitParseAndCompileWithTrivialFallback', $emit);
        $this->assertStringContainsString('RuntimeEmitTuAlloc::emit', $emit);
        $this->assertStringContainsString('RuntimeEmitTuInit::emitInitSequence', $emit);
        $init = (string) file_get_contents(self::$root.'/lib/JIT/RuntimeEmitTuInit.php');
        $this->assertStringContainsString('RuntimeInitVmContext::emit', $init);
        $object = (string) file_get_contents(self::$root.'/lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('allocateEmitTuShell', $object);
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('emitMainEntry', $jit);
        $this->assertStringContainsString('compileM3EmitTuRuntimeMethodFromQueue', $jit);
        $this->assertStringContainsString('compileM3EmitTuRuntimeMethodFromDeclareClassBlocks', $jit);
        $execute = (string) file_get_contents(self::$root.'/script/bootstrap-m3-emit-tu-execute.sh');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER=1', $execute);
    }

    public function testM3EmitTuMainUsesNativeBridgeEntry(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('compileM3EmitTuMainNative', $jit);
        $this->assertStringContainsString('BootstrapCompileSmokeM3Emit::emitMainEntry', $jit);
        $this->assertStringContainsString('compileM3EmitTuRuntimeMethodFromQueue', $jit);
    }

    public function testM3EmitTuRealLoweringSkipsEarlyParseStubDecl(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('shouldUseM3CompileDriverRealLowering()', $jit);
        $this->assertStringContainsString('compileM3EmitTuRuntimeSpineMethodsForRealLowering', $jit);
        $this->assertStringContainsString('emitMainEntry', $jit);
        $this->assertStringContainsString('M3EmitTuTrivialEchoAot::registerLinktime', $jit);
        $this->assertStringContainsString('shouldUseEmitHelperLinkStubs()', $jit);
        $this->assertStringContainsString('M3EmitTuTrivialEchoAot::isRegistered', $jit);
        $this->assertStringContainsString('runtime_trivial_echo.php', $jit);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $jit);
        $this->assertStringContainsString('compile_smoke_m3_emit', $jit);
        $smoke = (string) file_get_contents(self::$root.'/test/bootstrap-aot/compile_smoke_m3_emit.php');
        $this->assertStringContainsString('getLastParseFailure', $smoke);
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('peeklastparsefailure', $emit);
        $this->assertStringContainsString('echoLastParseFailureSuffix', $emit);
        $this->assertStringContainsString('noteparsecompilenullforscript', $emit);
        $this->assertStringContainsString('helloworld_compile_smoke', $jit);
        $this->assertStringContainsString('HELLOWORLD_SIDECAR_REL', $jit);
        $this->assertStringContainsString('COMPILE_SMOKE_SIDECAR_REL', $jit);
        $this->assertMatchesRegularExpression(
            '/compileM3EmitTuRuntimeSpineDecls\([^)]*\): void[\s\S]*?compileM3EmitTuRuntimeSpineMethodsForRealLowering/',
            $jit
        );
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1', $compile);
        $aot = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('emitParseAndCompileWithTrivialFallback', $aot);
    }

    /** Issue #3023: tail phi must use afterRecord predecessor, not compileBb. */
    public function testM3EmitParseAndCompileDefaultPhiUsesAfterRecordTail(): void
    {
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('$phi->addIncoming($block, $afterRecordBb)', $emit);
        $this->assertStringContainsString('shouldEmitRuntimeSpineDiagnosticStub', $emit);
    }

    /** Runtime.php CFG uses bare init* names; emit TU must match them (#2568). */
    public function testM3EmitTuRuntimeMethodFromModulesMatchesBareCfgFuncNames(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('$funcLc !== $methodLc', $jit);
        $this->assertStringContainsString("'initparsepipeline'", $jit);
        $this->assertStringContainsString('compileM3EmitTuRuntimeSpineMethodsForRealLowering', $jit);
    }

    public function testM3EmitTuUsesRuntimeInitCompilerFloor(): void
    {
        $init = (string) file_get_contents(self::$root.'/lib/JIT/RuntimeEmitTuInit.php');
        $this->assertStringContainsString('RuntimeInitCompiler::emit', $init);
        $compiler = (string) file_get_contents(self::$root.'/lib/JIT/RuntimeInitCompiler.php');
        $this->assertStringContainsString("'PHPCompiler\\\\Compiler'", $compiler);
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString("'PHPCompiler\\\\Runtime::'", $emit);
    }

    /** M3 compile_driver must C-floor initCompiler, not only emit TU (#2568). */
    public function testM3CompileDriverInitCompilerUsesRuntimeInitCompilerFloor(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString(
            'shouldUseM3EmitTuNativeBridge() || $this->shouldUseM3CompileDriverRealLowering()',
            $jit
        );
        $this->assertStringContainsString('RuntimeInitCompiler::emit', $jit);
    }

    public function testEmitTuModeDetectsHelperLinkEnv(): void
    {
        $source = (string) file_get_contents(self::$root.'/lib/JIT/EmitTuMode.php');
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_TU', $source);
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString("require_once __DIR__.'/EmitTuMode.php';", $emit);
        $runtime = (string) file_get_contents(self::$root.'/lib/Runtime.php');
        $this->assertStringContainsString('EmitTuMode::isMinimalRuntime', $runtime);
        $gen1 = (string) file_get_contents(self::$root.'/script/bootstrap-loop-gen1-link.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_RUNTIME_COMPILE:-1', $gen1);
        $probe = (string) file_get_contents(self::$root.'/script/bootstrap-loop-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_RUNTIME_COMPILE:-1', $probe);
    }
}
