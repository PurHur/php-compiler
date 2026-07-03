<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class BootstrapChangedSourcesProbeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testChangedSourcesProbeScriptAndMakefileTargetExist(): void
    {
        $script = self::$root.'/script/bootstrap-changed-sources-probe.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), $script.' must be executable');

        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-changed-sources-probe:', $body);
        $this->assertStringContainsString('examples/000-HelloWorld/example.php', $body);
        $this->assertStringContainsString('lib/OpCode.php', $body);
        $this->assertStringContainsString('bootstrap_gen3_emit_matches_stale_prelinked_gen0', $body);
        $this->assertStringContainsString('stale prelinked/bootstrap-gen0/', $body);
        $this->assertStringContainsString('#8710', $body);
        $this->assertStringContainsString('#15598', $body);
        $this->assertStringContainsString('bootstrap_compile_invoke', $body);
        $this->assertStringContainsString('BOOTSTRAP_GEN0_ZEND_ONLY=1', $body);
        $this->assertStringContainsString('bootstrap_honest_compile_log_uses_sidecar_recovery', $body);

        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('bootstrap-changed-sources-probe:', $makefile);
        $this->assertStringContainsString('./script/bootstrap-changed-sources-probe.sh', $makefile);
    }

    public function testBootstrapDevWorkflowDocumentsChangedSourcesProbe(): void
    {
        $doc = (string) file_get_contents(self::$root.'/docs/bootstrap-dev-workflow.md');
        $this->assertStringContainsString('#15598', $doc);
        $this->assertStringContainsString('bootstrap-changed-sources-probe', $doc);
    }

    public function testResolveCompileInvokeRegistersChangedSourcesSidecar(): void
    {
        $script = (string) file_get_contents(self::$root.'/script/bootstrap-resolve-compile-invoke.sh');
        $this->assertStringContainsString('changed_sources_smoke/main.php', $script);
        $this->assertStringContainsString('.m3_changed_sources_smoke_aot_blob', $script);
    }
}
