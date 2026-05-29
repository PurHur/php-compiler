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

    public function testCompilerUnitProbeScriptDocumentsEmitPathAndStrict(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1', $source);
        $this->assertStringContainsString('emit_path=', $source);
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $source);
        $this->assertStringContainsString('inventory compile_driver', $source);
        $this->assertStringContainsString('compiler_unit_probe/compile_driver.php', $source);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $source);
        $this->assertStringContainsString('compiler unit probe compile OK', $source);
    }

    /** Issue #2879: inventory compile_driver without *_m3_emit_native_entry.php. */
    public function testCompilerUnitProbeDocumentsInventoryEmitDriverOptIn(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $script);
        $this->assertStringContainsString('inventory compile_driver', $script);
        $this->assertFileExists(self::$root.'/test/selfhost/compiler_unit_probe/compile_driver.php');
    }

    public function testCompilePhpRecognizesCompilerUnitProbeM3EmitEntry(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('compiler_unit_probe/compile_driver.php', $compile);
        $this->assertStringContainsString('PHP_COMPILER_EMIT_HELPER_LINK=1', $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_TU=1', $compile);
    }

    public function testJitCachesCompilerUnitProbeFixtureSidecar(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('compiler_unit_probe_compile.php', $jit);
        $this->assertStringContainsString('COMPILER_UNIT_PROBE_SIDECAR_REL', $jit);
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILER_UNIT_PROBE_EMIT', $compile);
        $aot = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('COMPILER_UNIT_PROBE_SIDECAR_REL', $aot);
    }

    public function testCompilerUnitProbeFixtureLintPasses(): void
    {
        $fixture = self::$root.'/test/selfhost/compiler_unit_probe/compiler_unit_probe_compile.php';
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($fixture).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testCompilerUnitProbeStrictGreenWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 compiler unit probe strict test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-compiler-unit-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'BOOTSTRAP_M3_COMPILER_UNIT_PROBE_STRICT=1',
            'BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1',
            'BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1',
            'BOOTSTRAP_M3_RUNTIME_COMPILE=1',
            'bash',
            $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('emit_path=native', $out);
        $this->assertStringContainsString('compiler unit probe compile OK', $out);
        $this->assertTrue(is_executable(self::$root.'/build/compiler-unit-probe-aot'));
    }
}
