<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make north-star2-verify / script/north-star2-verify.sh (issue #1865).
 */
final class NorthStar2VerifyScriptTest extends TestCase
{
    public function testNorthStar2VerifyScriptExistsAndPrintsHelp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/north-star2-verify.sh';
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
        $this->assertStringContainsString('north-star2-verify', $combined);
        $this->assertStringContainsString('phpc doctor --gates', $combined);
        $this->assertStringContainsString('bootstrap-inventory.php', $combined);
        $this->assertStringContainsString('bootstrap-wave-check', $combined);
        $this->assertStringContainsString('lib spine', $combined);
        $this->assertStringContainsString('bootstrap-loop-probe', $combined);
        $this->assertStringContainsString('#1492', $combined);
        $this->assertStringContainsString('#1865', $combined);
    }

    public function testNorthStar2VerifyScriptDocumentsSteps(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star2-verify.sh');
        $this->assertStringContainsString('doctor --gates', $body);
        $this->assertStringContainsString('bootstrap-inventory.php', $body);
        $this->assertStringContainsString('bootstrap-wave-check.sh', $body);
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_SMOKE=1', $body);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke', $body);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $body);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $body);
        $this->assertStringContainsString('--dry-run', $body);
        $this->assertStringContainsString('ci_llvm_ready', $body);
    }

    public function testMakefileDeclaresNorthStar2VerifyTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('north-star2-verify:', $makefile);
        $this->assertStringContainsString('script/north-star2-verify.sh', $makefile);
    }
}
