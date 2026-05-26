<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapTypesUnitProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @group llvm
     */
    public function testNativeTypesUnitProbeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for types unit probe native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-types-unit-probe.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe: OK', $out);
        $binary = self::$root.'/build/selfhost-types-unit-probe';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('types_unit_probe bundle OK', $runOut);
    }

    public function testTypesUnitProbeLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/types_unit_probe/main.php';
        $this->assertFileExists($entry);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testTypesSmokeUnderZend(): void
    {
        require_once self::$root.'/test/selfhost/types_unit_probe/types_unit_probe_types.php';
        $result = types_unit_probe_types_smoke();
        if (str_starts_with($result, 'types_unit_probe types SKIP')) {
            $this->markTestSkipped($result);
        }
        $this->assertSame('types_unit_probe types OK', $result);
    }

    public function testTypesUnitProbeEntryDocumentsJitTypeSlice(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/types_unit_probe/main.php');
        $this->assertStringContainsString('lib/JIT.php', $entry);
        $this->assertStringContainsString('JIT\\Builtin\\Type', $entry);
        $this->assertStringContainsString('types_unit_probe bundle OK', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $entry);
        $typesHelper = (string) file_get_contents(self::$root.'/test/selfhost/types_unit_probe/types_unit_probe_types.php');
        $this->assertStringContainsString('types_unit_probe_types_smoke', $typesHelper);
        $this->assertStringContainsString('TYPE_INTERSECTION', $typesHelper);
        $this->assertStringContainsString('TYPE_UNION', $typesHelper);
        $this->assertStringContainsString('fromTypeDecl', $typesHelper);
    }

    public function testProbeScriptDocumentsGateAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-types-unit-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT=1', $script);
        $this->assertStringContainsString('selfhost-types-unit-probe', $script);
        $this->assertStringContainsString('types_unit_probe bundle OK', $script);
    }

    public function testMakefileDefinesTypesUnitProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe:', $makefile);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $makefile);
    }

    public function testCiDefaultsEnvDefinesPhptypesUnitProbeGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE="${BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2430', $defaults);
        $this->assertStringContainsString('#2433', $defaults);
        $this->assertStringContainsString('#2436', $defaults);
    }

    public function testCiLocalHonorsPhptypesUnitProbeGate(): void
    {
        $local = (string) file_get_contents(self::$root.'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_phptypes_unit_probe', $local);

        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $common);
    }
}
