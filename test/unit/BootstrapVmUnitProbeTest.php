<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapVmUnitProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @group llvm
     */
    public function testNativeVmUnitProbeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for VM unit probe native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-vm-unit-probe.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe: OK', $out);
        $binary = self::$root.'/build/selfhost-vm-unit-probe';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('vm_unit_probe bundle OK', $runOut);
    }

    public function testVmUnitProbeLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/vm_unit_probe/main.php';
        $this->assertFileExists($entry);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testVmUnitProbeEntryDocumentsVmSliceAndProbe(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/vm_unit_probe/main.php');
        $this->assertStringContainsString('lib/VM.php', $entry);
        $this->assertStringContainsString('vm_unit_probe bundle OK', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $entry);
    }

    public function testProbeScriptDocumentsGateAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-vm-unit-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT=1', $script);
        $this->assertStringContainsString('selfhost-vm-unit-probe', $script);
        $this->assertStringContainsString('vm_unit_probe bundle OK', $script);
    }

    public function testMakefileDefinesVmUnitProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe:', $makefile);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $makefile);
    }

    public function testCiDefaultsEnvDefinesVmUnitProbeGateDefaultOff(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_VM_UNIT_PROBE_GATE="${BOOTSTRAP_VM_UNIT_PROBE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2354', $defaults);
    }

    public function testCiLocalHonorsVmUnitProbeGate(): void
    {
        $local = (string) file_get_contents(self::$root.'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_vm_unit_probe', $local);

        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $common);
    }
}
