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
        $this->assertStringContainsString('.m3_compiler_lib_sidecar.sha', $script);
        $this->assertStringContainsString('BOOTSTRAP_VM_DRIVER_EXECUTE_PROBE_FULL_LINK', $script);
        $this->assertStringContainsString('bootstrap_copy_prelinked_compiler_lib_spine_blob', $script);
        $this->assertStringContainsString('bootstrap_vm_driver_execute_probe_llvm_env', $script);
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

    public function testNativeMainEnvProbePrintsVmDriverOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for VM driver execute native probe test.');
        }

        $out = self::$root.'/build/.bootstrap-vm-driver-execute-probe-minimal-aot';
        $entry = self::$root.'/test/selfhost/compiler_minimal/main.php';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $compileCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'php',
            self::$root.'/bin/compile.php',
            '-o',
            $out,
            $entry,
        ])).' 2>&1';
        exec($compileCmd, $compileLines, $compileCode);
        $compileOut = implode("\n", $compileLines);
        $this->assertSame(0, $compileCode, $compileOut);
        $this->assertTrue(is_executable($out), $out);

        $runCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_VM_DRIVER_EXECUTE=1',
            $out,
        ])).' 2>&1';
        exec($runCmd, $runLines, $runCode);
        $runOut = implode("\n", $runLines);
        $this->assertSame(0, $runCode, $runOut);
        $this->assertStringContainsString('vm driver ok', $runOut);
    }
}
