<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/009-FastCGIWeb CGI health + PATH_INFO (#2331, #2352).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group fastcgiweb
 * @group fastcgiweb-aot-execute
 */
final class FastCGIWebAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    protected function setUp(): void
    {
        $gate = getenv('FASTCGI_WEB_AOT_SMOKE_GATE');
        if (false === $gate || '' === $gate || '1' !== $gate) {
            $this->markTestSkipped(
                'FASTCGI_WEB_AOT_SMOKE_GATE=0 — set to 1 to run FastCGIWeb AOT execute tests (#2352)'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/examples/009-FastCGIWeb';
        if (!is_file($project.'/example.php')) {
            $this->markTestSkipped('examples/009-FastCGIWeb missing (#2331)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    public function testHealthReturnsOk(): void
    {
        $env = $this->baseEnv();
        $env['QUERY_STRING'] = '';
        $env['REQUEST_URI'] = '/example.php';
        $env['SCRIPT_NAME'] = '/example.php';

        $out = $this->runBinary($env);
        $this->assertStringContainsString('ok', $out);
    }

    public function testPathInfoDiagnostics(): void
    {
        $env = $this->baseEnv();
        $env['PATH_INFO'] = '/ping';
        $env['REQUEST_URI'] = '/example.php/ping';
        $env['SCRIPT_NAME'] = '/example.php';

        $out = $this->runBinary($env);
        $this->assertStringContainsString('PATH_INFO=', $out);
        $this->assertStringContainsString('REQUEST_URI=', $out);
        $this->assertStringContainsString('SCRIPT_NAME=', $out);
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     */
    private function runBinary(array $env): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $run = proc_open([$this->binary], $descriptorSpec, $pipes, null, $env);
        $this->assertIsResource($run);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($run);
        $this->assertSame(0, $exitCode, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? $stdout : '';
    }
}
