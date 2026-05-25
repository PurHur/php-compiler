<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make north-star1-verify / script/north-star1-verify.sh (issue #1845).
 */
final class NorthStar1VerifyScriptTest extends TestCase
{
    public function testNorthStar1VerifyScriptExistsAndPrintsHelp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/north-star1-verify.sh';
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
        $this->assertStringContainsString('north-star1-verify', $combined);
        $this->assertStringContainsString('phpc doctor --gates', $combined);
        $this->assertStringContainsString('miniwebapp-gates', $combined);
        $this->assertStringContainsString('ci-fast.sh', $combined);
        $this->assertStringContainsString('MiniWebApp AOT execute', $combined);
        $this->assertStringContainsString('examples-web-smoke', $combined);
        $this->assertStringContainsString('#1044', $combined);
        $this->assertStringContainsString('#1845', $combined);
    }

    public function testNorthStar1VerifyScriptDocumentsSteps(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/north-star1-verify.sh');
        $this->assertStringContainsString('doctor --gates', $body);
        $this->assertStringContainsString('miniwebapp-gates.sh', $body);
        $this->assertStringContainsString("ci-fast.sh", $body);
        $this->assertStringContainsString("MiniWebApp", $body);
        $this->assertStringContainsString('ci_run_miniwebapp_aot_execute', $body);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE=1', $body);
        $this->assertStringContainsString('examples-web-smoke.sh', $body);
        $this->assertStringContainsString('ci_llvm_ready', $body);
    }

    public function testMakefileDeclaresNorthStar1VerifyTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('north-star1-verify:', $makefile);
        $this->assertStringContainsString('script/north-star1-verify.sh', $makefile);
    }
}
