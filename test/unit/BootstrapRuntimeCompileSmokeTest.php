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
        $this->assertStringContainsString('runtime_compile_smoke_m3_emit: compile OK', $source);
    }

    public function testCompilePhpPreservesSelfhostAotForRuntimeM3NativeEmitEntry(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('runtime_m3_emit_native_entry.php', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_DRIVER', $compile);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $compile);
    }

    public function testRuntimeCompileSmokeProbeSetsEmitHelperLinkEnv(): void
    {
        $source = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-runtime-compile-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $source);
    }
}
