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
        $this->assertStringContainsString('bootstrap_compiler_lib_spine_entry_sha', $script);
        $this->assertStringContainsString('.m3_compiler_lib_sidecar.sha', $script);
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

    public function testSpineLinkScriptRefreshesSidecarAfterHonestBundleEmit(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-lib-spine-smoke-link.sh');
        $this->assertStringContainsString('compiler_lib_spine_smoke bundle OK', $script);
        $this->assertStringContainsString('.m3_compiler_lib_sidecar.sha', $script);
        $this->assertStringContainsString('8192M', $script);
        $this->assertStringContainsString('honest emit required (#8559)', $script);
        $this->assertStringContainsString('retrying honest Zend', $script);
        $this->assertStringContainsString('refreshed prelinked', $script);
        $this->assertStringContainsString('bootstrap_compiler_lib_honest_zend_compile', $script);
    }

    public function testNativeMainEnvProbePrintsVmDriverOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for VM driver execute native probe test.');
        }

        $out = self::$root.'/build/.bootstrap-vm-driver-execute-probe-minimal-aot';
        $entry = self::$root.'/test/selfhost/compiler_minimal/main.php';
        $prefix = LlvmToolchain::envPrefix(self::$root);
        $memoryLimit = '8192M';
        $compileCmd = implode(' ', array_map('escapeshellarg', [
            ...$prefix,
            'env',
            'PHP_COMPILER_MEMORY_LIMIT='.$memoryLimit,
            'php',
            '-d',
            'memory_limit='.$memoryLimit,
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
