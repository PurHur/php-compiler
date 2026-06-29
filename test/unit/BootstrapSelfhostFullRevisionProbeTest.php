<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class BootstrapSelfhostFullRevisionProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testFullRevisionProbeScriptUsesArgvOnly(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-full-revision-probe.sh');
        $this->assertStringContainsString('bootstrap-selfhost-full-revision-probe:', $script);
        $this->assertStringContainsString('bin/compile.php', $script);
        $this->assertStringContainsString('bin-compile-aot-inventory', $script);
        $this->assertStringContainsString('bootstrap-ensure-inventory-argv-driver', $script);
        $this->assertStringContainsString('bootstrap_ensure_inventory_argv_driver_ssot', $script);
        $this->assertStringContainsString('compiler_unit_probe_compile.php', $script);
        $this->assertStringContainsString('env -u PHP_COMPILER_M3_SOURCE -u PHP_COMPILER_M3_OUT', $script);
        $this->assertStringContainsString('PHP_COMPILER_REPO_ROOT="${ROOT}"', $script);
        $this->assertStringContainsString('emit_path=native', $script);
        $this->assertStringContainsString('#2880', $script);
        $this->assertStringContainsString('compile_smoke_m3_emit:', $script);
        $this->assertStringContainsString('bootstrap_gen3_emit_matches_stale_prelinked_gen0', $script);
        $this->assertStringContainsString('stale prelinked/bootstrap-gen0/', $script);
        $this->assertStringContainsString('#8710', $script);
    }

    public function testHelloworldCompileBinLinksInventoryBinCompile(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-compile-bin.sh');
        $this->assertStringContainsString('PHP_COMPILER_M3_INVENTORY_EMIT_DRIVER=1', $script);
        $this->assertStringContainsString('bin/compile.php"', $script);
        $this->assertStringContainsString('#2900', $script);
        $this->assertStringContainsString('#3011', $script);
    }

    public function testMakefileExposesFullRevisionProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-full-revision-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-selfhost-full-revision-probe.sh', $makefile);
    }

    public function testHelloworldCompileBinSyncsBinCompileSidecar(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-helloworld-compile-bin.sh');
        $this->assertStringContainsString('.m3_bin_compile_aot_blob', $script);
        $this->assertStringContainsString('#2880', $script);
    }

    public function testInventoryArgvDriverSmokeIncludesM4BinCompileLint(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('bootstrap_inventory_argv_driver_m4_smoke', $script);
        $this->assertStringContainsString('bootstrap_inventory_bin_compile_m4_sidecar_recover', $script);
        $this->assertStringContainsString('bin/compile.php lint', $script);
        $this->assertStringContainsString('bin/compile.php argv compile', $script);
        $this->assertStringContainsString('prelinked gen-0 sidecar', $script);
        $this->assertStringContainsString('#2880', $script);
        $this->assertStringNotContainsString('bootstrap_inventory_argv_driver_spine_lint', $script);
    }

    public function testBinCompileSidecarPathNorm(): void
    {
        $norm = \PHPCompiler\JIT\M3EmitTuTrivialEchoAot::normalizeSidecarSourcePath(
            self::$root.'/bin/compile.php'
        );
        $this->assertSame('bin/compile.php', $norm);
    }

    public function testJitRegistersM5DriverHostForBinCompileSidecar(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString("'/bin/compile.php'))", $jit);
        $this->assertStringContainsString('PHP_COMPILER_M5_DRIVER_HOST', $jit);
        $this->assertStringContainsString('shouldUseM4BinCompileArgvMainNative', $jit);
        $this->assertStringContainsString('compileM3CompileDriverMainNative', $jit);
        $this->assertStringContainsString('#2900', $jit);
    }
}
