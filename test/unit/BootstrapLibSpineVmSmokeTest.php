<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapLibSpineVmSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testVmSpineSmokeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-lib-spine-vm-smoke.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testVmSpineSmokeScriptDocumentsEnvAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-vm-smoke.sh');
        $this->assertStringContainsString('PHP_COMPILER_VM_SPINE_SMOKE=1', $script);
        $this->assertStringContainsString('selfhost-lib-spine-smoke', $script);
        $this->assertStringContainsString('vm-spine-ok', $script);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke-link.sh', $script);
    }

    public function testSpineEntryBundlesVmRunSmokeDispatch(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('vm_run_smoke.php', $entry);
        $this->assertStringContainsString('PHP_COMPILER_VM_SPINE_SMOKE', $entry);
        $this->assertStringContainsString('vm-spine-ok', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke', $entry);
    }

    public function testMakefileDefinesVmSpineSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke:', $makefile);
    }

    public function testCliDriverSkipsArgvWhenVmSpineSmoke(): void
    {
        $driver = (string) file_get_contents(self::$root.'/src/cli_driver.php');
        $this->assertStringContainsString('PHP_COMPILER_VM_SPINE_SMOKE', $driver);
    }

    public function testWaveCheckDocumentsVmSpineSmokeFlag(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-wave-check.sh');
        $this->assertStringContainsString('--with-lib-spine-vm-smoke', $script);
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_VM_SMOKE', $script);
    }

    public function testCiDefaultsEnvDefinesVmSpineSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE="${BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE:-1}"',
            $defaults
        );
    }
}
