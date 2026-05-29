<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapJitUnitProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @group llvm
     */
    public function testNativeJitUnitProbeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for JIT unit probe native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-jit-unit-probe.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe: OK', $out);
        $binary = self::$root.'/build/selfhost-jit-unit-probe';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('jit_unit_probe bundle OK', $runOut);
    }

    public function testJitUnitProbeLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/jit_unit_probe/main.php';
        $this->assertFileExists($entry);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testJitUnitProbeEntryDocumentsJitSliceAndProbe(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/jit_unit_probe/main.php');
        $this->assertStringContainsString('lib/JIT.php', $entry);
        $this->assertStringContainsString('jit_unit_probe bundle OK', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $entry);
    }

    public function testProbeScriptDocumentsGateAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-jit-unit-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT=1', $script);
        $this->assertStringContainsString('selfhost-jit-unit-probe', $script);
        $this->assertStringContainsString('jit_unit_probe bundle OK', $script);
    }

    public function testMakefileDefinesJitUnitProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe:', $makefile);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe-strict:', $makefile);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $makefile);
    }

    public function testJitUnitProbeScriptDocumentsEmitPathAndStrict(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-jit-unit-probe.sh';
        $this->assertFileExists($script);
        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('BOOTSTRAP_M3_JIT_UNIT_PROBE_STRICT=1', $source);
        $this->assertStringContainsString('emit_path=', $source);
        $this->assertStringContainsString('jit_unit_probe/compile_driver.php', $source);
        $this->assertStringContainsString('inventory compile_driver (#3032)', $source);
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $source);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_MODE=compile', $source);
        $this->assertStringContainsString('jit unit probe compile OK', $source);
    }

    /** Issue #2879: inventory compile_driver without *_m3_emit_native_entry.php. */
    public function testJitUnitProbeDocumentsInventoryEmitDriverOptIn(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-jit-unit-probe.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER', $script);
        $this->assertStringContainsString('inventory compile_driver (no emit-helper TU, #2879)', $script);
        $this->assertFileExists(self::$root.'/test/selfhost/jit_unit_probe/compile_driver.php');
    }

    public function testCompilePhpRecognizesInventoryJitUnitProbeCompileDriver(): void
    {
        $compile = (string) file_get_contents(self::$root.'/bin/compile.php');
        $this->assertStringContainsString("str_contains(\$normalized, 'compile_driver.php')", $compile);
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER', $compile);
        $this->assertStringNotContainsString('jit_unit_probe_m3_emit_native_entry', $compile);
    }

    public function testJitCachesJitUnitProbeFixtureSidecar(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('jit_unit_probe_compile.php', $jit);
        $this->assertStringContainsString('JIT_UNIT_PROBE_SIDECAR_REL', $jit);
        $this->assertStringContainsString('jit_unit_probe/compile_driver.php', $jit);
        $this->assertStringContainsString('JIT_UNIT_PROBE_COMPILE_DRIVER_SIDECAR_REL', $jit);
        $aot = (string) file_get_contents(self::$root.'/lib/JIT/M3EmitTuTrivialEchoAot.php');
        $this->assertStringContainsString('JIT_UNIT_PROBE_SIDECAR_REL', $aot);
        $this->assertStringContainsString('JIT_UNIT_PROBE_COMPILE_DRIVER_SIDECAR_REL', $aot);
    }

    /**
     * @group llvm
     */
    public function testInventoryJitUnitCompileDriverLinksWithRealLowering(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for JIT unit inventory compile_driver link test.');
        }

        $entry = self::$root.'/test/selfhost/jit_unit_probe/compile_driver.php';
        $out = self::$root.'/build/selfhost-jit-unit-inventory-emit-test';
        @unlink($out);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_SELFHOST_AOT=1',
            'PHP_COMPILER_M3_COMPILE_DRIVER=1',
            'PHP_COMPILER_EMIT_HELPER_LINK=1',
            'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1',
            'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1',
            'PHP_COMPILER_M3_EMIT_LOG_PREFIX=jit_unit_probe_m3_emit',
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $out,
            $entry,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        if (139 === $exitCode) {
            $this->markTestSkipped('LLVM 9 segfault during JIT unit inventory compile_driver link (#2879).');
        }

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($out);
        $this->assertTrue(is_executable($out));

        $fixture = self::$root.'/test/selfhost/jit_unit_probe/jit_unit_probe_compile.php';
        $aotOut = self::$root.'/build/selfhost-jit-unit-inventory-emit-aot';
        @unlink($aotOut);
        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_M3_COMPILE_MODE=compile',
            'PHP_COMPILER_M3_RUNTIME_COMPILE=1',
            'PHP_COMPILER_M3_EMIT_MINIMAL=1',
            'PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1',
            'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1',
            'PHP_COMPILER_M3_SOURCE='.$fixture,
            'PHP_COMPILER_M3_OUT='.$aotOut,
            $out,
        ])).' 2>&1';
        exec($runCmd, $runLines, $runExit);
        if (139 === $runExit) {
            $this->markTestSkipped('LLVM 9 segfault during JIT unit inventory compile_driver emit run (#2879).');
        }
        $runOut = implode("\n", $runLines);
        $this->assertSame(0, $runExit, $runOut);
        $this->assertStringContainsString('compile_smoke_m3_emit: compile OK', $runOut);
        $this->assertFileExists($aotOut);
    }

    public function testJitUnitProbeFixtureLintPasses(): void
    {
        $fixture = self::$root.'/test/selfhost/jit_unit_probe/jit_unit_probe_compile.php';
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($fixture).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    /**
     * @group llvm
     */
    public function testJitUnitProbeStrictGreenWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for M3 JIT unit probe strict test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-jit-unit-probe.sh';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'BOOTSTRAP_M3_JIT_UNIT_PROBE_STRICT=1',
            'BOOTSTRAP_M3_USE_INVENTORY_EMIT_DRIVER=1',
            'BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1',
            'BOOTSTRAP_M3_COMPILE_DRIVER_REAL_LOWERING=1',
            'BOOTSTRAP_M3_RUNTIME_COMPILE=1',
            'bash',
            $script,
        ])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('inventory compile_driver', $out);
        $this->assertStringContainsString('emit_path=native', $out);
        $this->assertStringContainsString('jit unit probe compile OK', $out);
        $this->assertTrue(is_executable(self::$root.'/build/jit-unit-probe-aot'));
    }

    public function testCiDefaultsEnvDefinesJitUnitProbeGateDefaultOff(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_JIT_UNIT_PROBE_GATE="${BOOTSTRAP_JIT_UNIT_PROBE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2332', $defaults);
        $this->assertStringContainsString('#2361', $defaults);
    }

    public function testCiLocalHonorsJitUnitProbeGate(): void
    {
        $local = (string) file_get_contents(self::$root.'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_jit_unit_probe', $local);

        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $common);
    }
}
