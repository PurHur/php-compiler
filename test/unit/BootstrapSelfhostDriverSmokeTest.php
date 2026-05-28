<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class BootstrapSelfhostDriverSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testDriverSmokeScriptDocumentsStages(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-driver-smoke.sh');
        $this->assertStringContainsString('bootstrap-selfhost-driver-smoke:', $script);
        $this->assertStringContainsString('compiler_smoke_standalone.php', $script);
        $this->assertStringContainsString('PHP_COMPILER_M3_COMPILE_MODE=compile', $script);
        $this->assertStringContainsString('emit_path=native', $script);
        $this->assertStringContainsString('compiler smoke', $script);
        $this->assertStringContainsString('selfhost-helloworld-compile', $script);
        $this->assertStringContainsString('bin-compile-aot', $script);
        $this->assertStringContainsString('"${BIN_COMPILE_DRIVER}" -o "${EMIT_OUT}"', $script);
        $this->assertStringContainsString('stage 5', $script);
        $this->assertStringContainsString('bootstrap-driver-smoke-gen3', $script);
        $this->assertStringContainsString('silent success guard #2890', $script);
        $this->assertStringNotContainsString(' -l ', $script);
    }

    public function testCompileSmokeM3EmitSetsM4BinCompileDriverForBinCompilePhp(): void
    {
        $source = (string) file_get_contents(self::$root.'/test/bootstrap-aot/compile_smoke_m3_emit.php');
        $this->assertStringContainsString('$isBinCompile', $source);
        $this->assertStringContainsString('PHP_COMPILER_M4_BIN_COMPILE_DRIVER=1', $source);
        $this->assertStringContainsString('PHP_COMPILER_M3_EMIT_LOG_PREFIX=compile_smoke_m3_emit', $source);
    }

    public function testCompileSmokeM3EmitRegistersLinkerPolyfillBeforeStandalone(): void
    {
        $source = (string) file_get_contents(self::$root.'/test/bootstrap-aot/compile_smoke_m3_emit.php');
        $this->assertStringContainsString('bootstrap_m3_emit_ensure_phpc_run_command', $source);
        $this->assertStringContainsString('phpc_run_command_polyfill.php', $source);
        $this->assertStringContainsString('bootstrap_m3_emit_ensure_phpc_run_command();', $source);
        $this->assertStringContainsString('$runtime->standalone', $source);
    }

    public function testMakefileExposesDriverSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-driver-smoke:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-selfhost-driver-smoke.sh', $makefile);
    }

    public function testCiDefaultsDefinesM5DriverGate(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_M5_DRIVER_SMOKE_GATE', $defaults);
        $this->assertStringContainsString('BOOTSTRAP_M5_DRIVER_GATE', $defaults);
    }

    public function testCiCommonWiresM5DriverSmoke(): void
    {
        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m5_driver_smoke', $common);
        $this->assertStringContainsString('bootstrap-selfhost-driver-smoke.sh', $common);
    }

    public function testFromDeclJunkFragmentsPatchRegistered(): void
    {
        $apply = (string) file_get_contents(self::$root.'/script/apply-patches.sh');
        $this->assertStringContainsString('php-types-fromdecl-junk-fragments.patch', $apply);
    }

    /** Issue #3004: argv bin/compile.php must not return null from parseAndCompile stub when sidecars miss. */
    public function testInventoryArgvDriverUsesParseCompileSpineNotNullStub(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $this->assertStringContainsString('inventoryArgvSidecar', $jit);
        $this->assertStringContainsString('shouldUseM4BinCompileArgvMainNative', $jit);
        $emit = (string) file_get_contents(self::$root.'/lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString("'compileemitsmoke', 'compile'", $emit);
        $this->assertStringContainsString("strtolower('PHPCompiler\\\\Runtime::parse')", $emit);
        $this->assertStringNotContainsString('unset($runtimeThis, $code, $filename)', $emit);
    }

    /** Issue #3012: inventory argv driver (helloworld prefix) must register spine smoke sidecar. */
    public function testHelloworldEmitPrefixRegistersCompilerLibSpineSidecar(): void
    {
        $jit = (string) file_get_contents(self::$root.'/lib/JIT.php');
        $needle = "'helloworld_compile_smoke' === \$logPrefix";
        $start = strpos($jit, $needle);
        $this->assertNotFalse($start, 'helloworld_compile_smoke logPrefix branch');
        $branch = substr($jit, $start, 4000);
        $this->assertStringContainsString('compiler_lib_spine_smoke/main.php', $branch);
        $this->assertStringContainsString('COMPILER_LIB_SIDECAR_REL', $branch);
        $this->assertStringContainsString('compilerLibSentinelBlock', $branch);
    }
}
