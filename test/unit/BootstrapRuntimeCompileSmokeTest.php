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

    public function testRuntimeCompileSmokeProbeScriptWiresNativeCompileDriver(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh';
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER', $source);
        $this->assertStringContainsString('runtime_m3_emit_native_entry.php', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER=1', $source);
        $this->assertStringContainsString('runtime_compile_smoke_m3_emit', $source);
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('isBootstrapM3RuntimeEmitBridgeName', $jit);
        $this->assertStringContainsString('runtime_compile_smoke_m3_emit', $jit);
        $this->assertStringContainsString('compileRuntimeParseAndCompileM3Native', $jit);
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('declareRuntimeParseAndCompileNative', $emit);
        $this->assertStringContainsString("'parseandcompileemitsmoke'", $emit);
    }

    public function testCompilePhpPreservesSelfhostAotForRuntimeM3NativeEmitEntry(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('runtime_m3_emit_native_entry.php', $compile);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_TU=1', $compile);
        $probe = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER=1', $probe);
    }

    public function testRuntimeCompileSmokeProbeSetsEmitHelperLinkEnv(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $source);
    }

    public function testM3EmitTuUsesMinimalRuntimeShellAlloc(): void
    {
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
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
        $this->assertStringContainsString('runtime_trivial_echo.php', $jit);
        $this->assertMatchesRegularExpression(
            '/compileM3EmitTuRuntimeSpineDecls\(\): void[\s\S]*?compileM3EmitTuRuntimeSpineMethodsForRealLowering/',
            $jit
        );
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_HELPER_SPINE=1', $compile);
        $aot = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('emitParseAndCompileWithTrivialFallback', $aot);
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
}
