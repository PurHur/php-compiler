<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make north-star4-verify / script/north-star4-verify.sh (issue #2379).
 */
final class NorthStar4VerifyScriptTest extends TestCase
{
    public function testNorthStar4VerifyScriptExistsAndPrintsHelp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/north-star4-verify.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script, '--help'], $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('north-star4-verify', $combined);
        $this->assertStringContainsString('bootstrap-inventory.php', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $combined);
        $this->assertStringContainsString('bootstrap-selfhost-compile-smoke-probe.sh', $combined);
        $this->assertStringContainsString('bootstrap-loop-gen1-link.sh', $combined);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $combined);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT', $combined);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT', $combined);
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT', $combined);
        $this->assertStringContainsString('--dry-run-only', $combined);
        $this->assertStringContainsString('--require-llvm', $combined);
        $this->assertStringContainsString('#2379', $combined);
        $this->assertStringContainsString('#1492', $combined);
        $this->assertStringContainsString('#2112', $combined);
        $this->assertStringContainsString('#1521', $combined);
    }

    public function testNorthStar4VerifyScriptDocumentsSteps(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star4-verify.sh');
        $this->assertStringContainsString('bootstrap-inventory.php', $body);
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict', $body);
        $this->assertStringContainsString('ci_run_bootstrap_m3_compile_smoke_strict', $body);
        $this->assertStringContainsString('bootstrap-loop-gen1-link.sh', $body);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $body);
        $this->assertStringContainsString('BOOTSTRAP_M4_LINK_COMPILE_DRIVER=1', $body);
        $this->assertStringContainsString('BOOTSTRAP_M4_RUNTIME_COMPILE=1', $body);
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT', $body);
        $this->assertStringContainsString('ci_llvm_ready', $body);
        $this->assertStringContainsString('north-star4-verify: OK', $body);
        $this->assertStringContainsString('ns4_print_m4_next_steps', $body);
        $this->assertStringContainsString('#2429', $body);
        $this->assertStringContainsString('#2075', $body);
    }

    public function testMakefileDeclaresNorthStar4VerifyTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('north-star4-verify:', $makefile);
        $this->assertStringContainsString('script/north-star4-verify.sh', $makefile);
    }
}
