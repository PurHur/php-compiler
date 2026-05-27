<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapCompilerUnitProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @group llvm
     */
    public function testNativeCompilerUnitProbeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for compiler unit probe native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe: OK', $out);
        $binary = self::$root.'/build/selfhost-compiler-unit-probe';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('compiler_unit_probe bundle OK', $runOut);
    }

    public function testCompilerUnitProbeLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/compiler_unit_probe/main.php';
        $this->assertFileExists($entry);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testCompilerUnitProbeCompileSmokeFixtureExists(): void
    {
        $fixture = self::$root.'/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php';
        $this->assertFileExists($fixture);
        require_once self::$root.'/test/bootstrap-aot/compiler_unit_probe_compile_smoke.php';
        $this->assertSame(
            0,
            \PHPCompiler\BootstrapAot\compiler_unit_probe_compile_smoke($fixture)
        );
    }

    public function testMakefileDefinesCompilerUnitProbeStrictTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe-strict:', $makefile);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1', $makefile);
        $jit = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('COMPILER_UNIT_PROBE_SIDECAR_REL', $jit);
    }

    public function testBootstrapSelfhostDocMentionsCompilerUnitProbeStrict(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe-strict', $doc);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT', $doc);
        $this->assertStringContainsString('#2618', $doc);
    }

    /**
     * @group llvm
     */
    public function testCompilerUnitProbeStrictNativeEmitWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for compiler unit probe strict emit test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1',
            'BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1',
            'BOOTSTRAP_M3_RUNTIME_COMPILE=1',
            'BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1',
            'bash',
            $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-unit-probe: OK emit_path=native', $out);
        $this->assertStringContainsString('compiler_unit_probe fixture OK', $out);
    }
}
