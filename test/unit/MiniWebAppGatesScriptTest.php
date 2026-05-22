<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * make miniwebapp-gates / script/miniwebapp-gates.sh (issues #472, #503).
 */
final class MiniWebAppGatesScriptTest extends TestCase
{
    public function testMiniWebAppGatesScriptExistsAndPrintsLadder(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/miniwebapp-gates.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script], $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('MiniWebApp CI gates', $combined);
        $this->assertStringContainsString('Stage 0 skeleton', $combined);
        $this->assertStringContainsString('Stage 1 lint green', $combined);
        $this->assertStringContainsString('MINIWEBAPP_LINT_GATE=1', $combined);
        $this->assertStringContainsString('MINIWEBAPP_VM_CLI_GATE', $combined);
        $this->assertStringContainsString('Stage 1b VM CLI', $combined);
        $this->assertStringContainsString('issues/597', $combined);
        $this->assertStringContainsString('issues/472', $combined);
        $this->assertStringContainsString('issues/454', $combined);
        $this->assertStringContainsString('issues/539', $combined);
        $this->assertStringNotContainsString('issues/67', $combined);
    }

    public function testMakefileDeclaresMiniWebAppGatesTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('miniwebapp-gates:', $makefile);
        $this->assertStringContainsString('script/miniwebapp-gates.sh', $makefile);
    }
}
