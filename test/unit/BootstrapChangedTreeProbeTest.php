<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group aot-lint */
final class BootstrapChangedTreeProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testBootstrapChangedTreeProbeScriptExists(): void
    {
        $script = self::$root.'/script/bootstrap-changed-tree-probe.sh';
        $this->assertFileExists($script);
        $this->assertFileIsReadable($script);
    }

    public function testBootstrapChangedTreeFixtureExistsAndLintPasses(): void
    {
        $fixture = self::$root.'/test/bootstrap-aot/changed_tree_probe.php';
        $this->assertFileExists($fixture);

        $prefix = LlvmToolchain::envPrefix(self::$root);
        $cmd = implode(' ', array_map('escapeshellarg', [...$prefix, 'php', self::$root.'/bin/compile.php', '-l', $fixture])).' 2>&1';
        exec($cmd, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
    }

    public function testBootstrapChangedTreeProbeDocumentsIssueAndLimitation(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-changed-tree-probe.sh');
        $this->assertStringContainsString('#15598', $script);
        $this->assertStringContainsString('PHP_COMPILER_BOOTSTRAP_CHANGED_TREE_MARKER', $script);
        $this->assertStringContainsString('CHANGED_TREE_PROBE_MARKER', $script);
        $this->assertStringContainsString('bootstrap_compile_invoke', $script);
        $this->assertStringContainsString('bootstrap-resolve-compile-invoke.sh', $script);
        $this->assertStringContainsString('Limitation', $script);
        $this->assertStringContainsString('exit 2', $script);
        $this->assertStringContainsString('changed_tree_probe.php', $script);

        $fixture = (string) file_get_contents(self::$root.'/test/bootstrap-aot/changed_tree_probe.php');
        $this->assertStringContainsString('CHANGED_TREE_PROBE_MARKER', $fixture);
        $this->assertStringContainsString('#15598', $fixture);
    }

    public function testMakefileDefinesBootstrapChangedTreeProbeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-changed-tree-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-changed-tree-probe.sh', $makefile);
    }

    public function testBootstrapDevWorkflowDocumentsChangedTreeStarter(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-dev-workflow.md');
        $this->assertStringContainsString('#15598', $doc);
        $this->assertStringContainsString('bootstrap-changed-tree-probe', $doc);
        $this->assertStringContainsString('starter landed', $doc);
    }
}
