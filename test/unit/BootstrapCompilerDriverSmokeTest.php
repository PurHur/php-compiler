<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapCompilerDriverSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testNativeCompilerDriverSmokeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for compiler driver smoke native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compiler-driver-smoke-link.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link: OK', $out);
        $binary = self::$root.'/build/selfhost-compiler-driver-smoke';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('compiler_driver_smoke bundle OK', $runOut);
    }

    public function testCompilerDriverSmokeLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_driver_smoke/main.php';
        $this->assertFileExists($entry);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testCompileDriverSmokeSkipsTopLevelEchoWhenCompilerLoaded(): void
    {
        $helper = (string) file_get_contents(self::$root.'/test/bootstrap-aot/compile_driver_smoke.php');
        $this->assertStringContainsString('class_exists(\\PHPCompiler\\Compiler::class, false)', $helper);
    }

    public function testMakefileDefinesCompilerDriverSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke:', $makefile);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link.sh', $makefile);
    }

    public function testCiDefaultsEnvDefinesCompilerDriverSmokeGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'COMPILER_DRIVER_SMOKE_GATE="${COMPILER_DRIVER_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2136', $defaults);
        $this->assertStringContainsString('#2137', $defaults);
        $this->assertStringContainsString('#2168', $defaults);
    }

    public function testCiLocalHonorsCompilerDriverSmokeGate(): void
    {
        $local = (string) file_get_contents(self::$root.'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_compiler_driver_smoke', $local);

        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $common);
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link.sh', $common);
    }
}
