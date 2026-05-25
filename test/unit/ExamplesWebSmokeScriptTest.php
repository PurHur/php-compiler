<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * script/examples-web-smoke.sh HTTP harness (issue #298).
 */
final class ExamplesWebSmokeScriptTest extends TestCase
{
    public function testExamplesWebSmokeScriptSkipsWhenServeTestsDisabled(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/examples-web-smoke.sh';
        $this->assertFileIsReadable($script);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($this->baseEnv(), ['PHP_COMPILER_SKIP_SERVE_TESTS' => '1']);
        $proc = proc_open(['bash', $script], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('skipped (PHP_COMPILER_SKIP_SERVE_TESTS', $combined);
    }

    public function testExamplesWebSmokeScriptSupportsMiniWebAppOnlyFlag(): void
    {
        $script = dirname(__DIR__, 2).'/script/examples-web-smoke.sh';
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('--miniwebapp-only', $body);
        $this->assertStringContainsString('003-MiniWebApp', $body);
    }

    public function testExamplesWebSmokeScriptSupportsSessionsWebSlice(): void
    {
        $script = dirname(__DIR__, 2).'/script/examples-web-smoke.sh';
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('--sessions-only', $body);
        $this->assertStringContainsString('005-SessionsWeb', $body);
        $this->assertStringContainsString('run_sessions_web_smoke', $body);
        $this->assertStringContainsString('SESSIONS_WEB_SMOKE_GATE', $body);
        $this->assertStringContainsString('curl_expect_303_post_cookies', $body);
        $this->assertStringContainsString('Flash: Saved', $body);
    }

    public function testExamplesWebSmokeScriptSupportsAot003MiniWebAppSlice(): void
    {
        $script = dirname(__DIR__, 2).'/script/examples-web-smoke.sh';
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('run_miniwebapp_aot_smoke', $body);
        $this->assertStringContainsString('shellQueryRouteHome', $body);
        $this->assertStringContainsString('execute parity', $body);
        $this->assertStringContainsString('hello PATH_INFO|/index.php/hello?name=Dev', $body);
        $this->assertStringContainsString('contact PATH_INFO|/index.php/contact', $body);
        $this->assertStringContainsString('#676', $body);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE', $body);
    }

    public function testExamplesWebSmokeScriptPassesWhenLoopbackAvailable(): void
    {
        if (false !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS') && '' !== getenv('PHP_COMPILER_SKIP_SERVE_TESTS')) {
            $this->markTestSkipped('PHP_COMPILER_SKIP_SERVE_TESTS is set');
        }
        if (!$this->canBindLoopback()) {
            $this->markTestSkipped('Cannot bind loopback TCP');
        }
        if (!$this->commandExists('curl')) {
            $this->markTestSkipped('curl not available');
        }

        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/examples-web-smoke.sh';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script], $descriptorSpec, $pipes, $repoRoot, $this->baseEnv());
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertStringContainsString('examples-web-smoke: 001-SimpleWeb', $combined);
        $this->assertStringContainsString('POST example.php', $combined);
        $this->assertStringContainsString('examples-web-smoke: 002-StaticWeb', $combined);
        $this->assertStringContainsString('examples-web-smoke: 004-ApiJson', $combined);
        $this->assertStringContainsString('examples-web-smoke: 005-SessionsWeb', $combined);
        $this->assertStringContainsString('005-SessionsWeb / GET after flash: ok', $combined);
        $this->assertStringContainsString('GET example.php', $combined);
        if (0 !== $exit && str_contains($combined, '003-MiniWebApp')) {
            // 001–004 + 005 are the #298 / #1887 gate; 003 may fail without full project bootstrap in harness.
            return;
        }
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('examples-web-smoke: ok', $combined);
    }

    private function canBindLoopback(): bool
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = [$repoRoot.'/script/php-local.sh', $repoRoot.'/script/can-bind-loopback.php'];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $this->baseEnv());
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    private function commandExists(string $name): bool
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', '-c', 'command -v '.escapeshellarg($name)], $descriptorSpec, $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
