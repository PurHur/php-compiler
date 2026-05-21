<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Makefile web-smoke preflight (issues #304, #455).
 */
final class WebSmokeScriptTest extends TestCase
{
    public function testWebSmokeScriptPassesOnShippedExamples(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/web-smoke.sh';
        $this->assertFileIsReadable($script);

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
        $this->assertStringContainsString('web-smoke: lint examples/', $combined);
        if (is_dir($repoRoot.'/examples/003-MiniWebApp/public')) {
            $this->assertStringContainsString('web-smoke: lint --all examples/003-MiniWebApp', $combined);
            $this->assertStringContainsString('web-smoke: 003-MiniWebApp:', $combined);
        }
        $this->assertStringContainsString('web-smoke: ok', $combined);
    }
}
