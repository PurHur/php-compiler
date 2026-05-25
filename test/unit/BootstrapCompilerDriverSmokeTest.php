<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group llvm */
final class BootstrapCompilerDriverSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testCompilerDriverSmokeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-compiler-driver-smoke.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testCompileDriverSmokeSkipsTopLevelEchoWhenCompilerLoaded(): void
    {
        $driver = (string) file_get_contents(self::$root.'/test/bootstrap-aot/compile_driver_smoke.php');
        $this->assertStringContainsString('class_exists(\\PHPCompiler\\Compiler::class, false)', $driver);
        $this->assertStringContainsString('compile_driver_smoke parse OK', $driver);
    }

    public function testNativeCompilerDriverSmokeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for compiler driver smoke native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compiler-driver-smoke.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke: OK', $out);
        $binary = self::$root.'/build/selfhost-compiler-driver-smoke';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('compiler_driver_smoke bundle OK', $runOut);
    }

    public function testMakefileDefinesCompilerDriverSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke:', $makefile);
    }

    public function testCiDefaultsEnvDefinesCompilerDriverSmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'COMPILER_DRIVER_SMOKE_GATE="${COMPILER_DRIVER_SMOKE_GATE:-0}"',
            $defaults
        );
    }
}
