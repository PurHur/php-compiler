<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapVmDriverExecuteProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testVmDriverExecuteProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-selfhost-vm-driver-execute-probe.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testVmDriverExecuteProbeScriptDocumentsEnvAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-vm-driver-execute-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_VM_DRIVER_EXECUTE=1', $script);
        $this->assertStringContainsString('selfhost-lib-spine-smoke', $script);
        $this->assertStringContainsString('vm driver ok', $script);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke-link.sh', $script);
    }

    public function testSpineEntryDocumentsVmDriverExecutePath(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PHP_COMPILER_VM_DRIVER_EXECUTE', $entry);
        $this->assertStringContainsString('vm driver ok', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-vm-driver-execute-probe', $entry);
    }

    public function testMakefileDefinesVmDriverExecuteTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-vm-driver-execute-probe:', $makefile);
    }
}
