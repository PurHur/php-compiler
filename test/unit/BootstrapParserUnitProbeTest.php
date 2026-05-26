<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapParserUnitProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @group llvm
     */
    public function testNativeParserUnitProbeLinkPrintsBundleOkWhenLlvmPresent(): void
    {
        if (!LlvmToolchain::isReady(self::$root)) {
            $this->markTestSkipped('LLVM 9 not available for parser unit probe native link test.');
        }

        $script = self::$root.'/script/bootstrap-selfhost-parser-unit-probe.sh';
        $this->assertFileExists($script);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'bash', $script])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $out = implode("\n", $lines);
        $this->assertSame(0, $exitCode, $out);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe: OK', $out);
        $binary = self::$root.'/build/selfhost-parser-unit-probe';
        $this->assertTrue(is_executable($binary), $binary);
        $runOut = shell_exec($binary);
        $this->assertIsString($runOut);
        $this->assertStringContainsString('parser_unit_probe bundle OK', $runOut);
    }

    public function testParserUnitProbeLintPasses(): void
    {
        $entry = self::$root.'/test/selfhost/parser_unit_probe/main.php';
        $this->assertFileExists($entry);
        $cmd = 'php '.escapeshellarg(self::$root.'/bin/compile.php').' -l '.escapeshellarg($entry).' 2>&1';
        exec($cmd, $lines, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testParserFixtureParsesUnderZend(): void
    {
        require_once self::$root.'/test/selfhost/parser_unit_probe/parser_unit_probe_parse.php';
        $result = parser_unit_probe_parse_smoke();
        $this->assertSame('parser_unit_probe parse OK', $result);
    }

    public function testParserUnitProbeEntryDocumentsParserSliceAndProbe(): void
    {
        $entry = (string) file_get_contents(self::$root.'/test/selfhost/parser_unit_probe/main.php');
        $this->assertStringContainsString('lib/Compiler.php', $entry);
        $this->assertStringContainsString('lib/Runtime.php', $entry);
        $this->assertStringContainsString('parser_unit_probe bundle OK', $entry);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $entry);
        $parseHelper = (string) file_get_contents(self::$root.'/test/selfhost/parser_unit_probe/parser_unit_probe_parse.php');
        $this->assertStringContainsString('parser_unit_probe_parse_smoke', $parseHelper);
        $this->assertStringContainsString('fixture.php', $parseHelper);
    }

    public function testProbeScriptDocumentsGateAndArtifact(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-selfhost-parser-unit-probe.sh');
        $this->assertStringContainsString('PHP_COMPILER_SELFHOST_AOT=1', $script);
        $this->assertStringContainsString('selfhost-parser-unit-probe', $script);
        $this->assertStringContainsString('parser_unit_probe bundle OK', $script);
    }

    public function testMakefileDefinesParserUnitProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe:', $makefile);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $makefile);
    }

    public function testCiDefaultsEnvDefinesParserUnitProbeGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(self::$root.'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_PARSER_UNIT_PROBE_GATE="${BOOTSTRAP_PARSER_UNIT_PROBE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2409', $defaults);
        $this->assertStringContainsString('#2417', $defaults);
        $this->assertStringContainsString('#2419', $defaults);
    }

    public function testCiLocalHonorsParserUnitProbeGate(): void
    {
        $local = (string) file_get_contents(self::$root.'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_parser_unit_probe', $local);

        $common = (string) file_get_contents(self::$root.'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $common);
    }
}
