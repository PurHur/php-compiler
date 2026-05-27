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
        $this->assertStringNotContainsString(' -l ', $script);
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
}
