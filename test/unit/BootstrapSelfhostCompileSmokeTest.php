<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapSelfhostCompileSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testNativeCompileSmokeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for self-host compile smoke native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compile-smoke-link.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compile-smoke-link: OK', $out);
        $binary = self::$root.'/build/selfhost-compile-smoke';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('compiler_compile_smoke bundle OK', $runOut);
    }

    public function testNativeCompileSmokeEchoRunPrintsGreetingWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for self-host compile smoke echo run test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compile-smoke-run.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compile-smoke-run: OK', $out);
        $binary = self::$root.'/build/selfhost-compile-smoke-echo';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertSame('compiler smoke', trim(str_replace("\n", '', $runOut)));
    }

    public function testCompileSmokeProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-compile-smoke-probe.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT=1', $source);
        $this->assertStringContainsString('emit_path=', $source);
        $this->assertStringContainsString('compiler_compile_smoke bundle OK', $source);
    }

    public function testCompileSmokeProbePartialGreenWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 compile-smoke probe test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compile-smoke-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compile-smoke-probe: OK', $out);
        $this->assertStringContainsString('OK emit_path=native', $out);
        $this->assertStringContainsString('compiler smoke', $out);
        $this->assertTrue(is_executable(self::$root.'/build/compile-smoke-aot'));
    }

    public function testCompileSmokeProbeDefaultsLinkCompileDriverWhenLlvmPresent(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compile-smoke-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1', $source);
        $this->assertStringContainsString('#2620', $source);
    }

    public function testCompileSmokeProbeCachesCompilerSmokeStandaloneSidecar(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('compile_smoke_m3_emit', $jit);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $jit);
        $this->assertStringContainsString('COMPILE_SMOKE_SIDECAR_REL', $jit);
        $aot = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('compileSmokeSentinelBlock', $aot);
    }

    public function testCompileSmokeProbeDefaultsRealLoweringWhenLinkCompileDriver(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compile-smoke-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING:-1', $source);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER:=1', $source);
    }

    public function testCompileSmokeProbeScriptWiresNativeCompileDriver(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-compile-smoke-probe.sh';
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER', $source);
        $this->assertStringContainsString('compile_smoke_m3_emit_native_entry.php', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER=1', $source);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $source);
    }

    public function testCompileDriverLintPasses(): void
    {
        $driver = self::$root.'/test/selfhost/compiler_compile_smoke/compile_driver.php';
        $this->assertFileExists($driver);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($driver).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testCompilePhpPreservesSelfhostAotForM3NativeEmitEntry(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('compile_smoke_m3_emit_native_entry.php', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER', $compile);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_TU=1', $compile);
    }

    public function testCompileSmokeProbeSetsEmitHelperLinkEnv(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compile-smoke-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $source);
    }
}
