<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * script/deploy-smoke.sh phpc deploy + PHPC_DEPLOY_ROOT harness (issue #718).
 */
final class DeploySmokeScriptTest extends TestCase
{
    public function testDeploySmokeScriptSkipsWhenLlvmMissing(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/deploy-smoke.sh';
        $this->assertFileIsReadable($script);

        $env = $this->baseEnv();
        unset($env['PHP_COMPILER_LLVM_PATH']);
        $env['PHP_COMPILER_LLVM_PATH'] = $repoRoot.'/.llvm-missing-probe-'.bin2hex(random_bytes(4));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
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
        $this->assertStringContainsString('skipped (LLVM 9 not available', $combined);
    }

    public function testDeploySmokeScriptDocumentsMiniWebAppSkip(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/deploy-smoke.sh');
        $this->assertStringContainsString('003-MiniWebApp', $body);
        $this->assertStringContainsString('DEPLOY_SMOKE_003_LAYOUT', $body);
        $this->assertStringContainsString('#804', $body);
        $this->assertStringContainsString('#764', $body);
        $this->assertStringContainsString('#612', $body);
        $this->assertStringContainsString('PHPC_DEPLOY_ROOT', $body);
        $this->assertStringContainsString('--example', $body);
        $this->assertStringContainsString('phpc deploy', $body);
    }

    public function testDeploySmokeExample003SkipsWhenLayoutGateOff(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/deploy-smoke.sh';

        $env = $this->baseEnv();
        unset($env['DEPLOY_SMOKE_003_LAYOUT']);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script, '--example', '003'], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        if (!LlvmToolchain::isReady($repoRoot)) {
            $this->assertSame(0, $exit, $combined);
            $this->assertStringContainsString('skipped (LLVM 9 not available', $combined);

            return;
        }

        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('003-MiniWebApp: skip', $combined);
        $this->assertStringContainsString('DEPLOY_SMOKE_003_LAYOUT=1', $combined);
    }

    public function testDeploySmokeExample003LayoutOnlyWhenEnabled(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $repoRoot = dirname(__DIR__, 2);
        $phpc = $repoRoot.'/phpc';
        if (!is_executable($phpc)) {
            $this->markTestSkipped('phpc wrapper not executable');
        }
        if (!is_dir($repoRoot.'/examples/003-MiniWebApp/public')) {
            $this->markTestSkipped('examples/003-MiniWebApp missing (#246)');
        }

        $script = $repoRoot.'/script/deploy-smoke.sh';
        $env = $this->baseEnv();
        $env['DEPLOY_SMOKE_003_LAYOUT'] = '1';

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script, '--example', '003'], $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('deploy-smoke: 003-MiniWebApp: layout ok', $combined);
        $this->assertStringContainsString('deploy-smoke: ok', $combined);
    }

    public function testDeploySmokeScriptPasses002WhenLlvmReady(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }

        $repoRoot = dirname(__DIR__, 2);
        $phpc = $repoRoot.'/phpc';
        if (!is_executable($phpc)) {
            $this->markTestSkipped('phpc wrapper not executable');
        }

        $script = $repoRoot.'/script/deploy-smoke.sh';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', $script, '--example', '002'], $descriptorSpec, $pipes, $repoRoot, $this->baseEnv());
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('deploy-smoke: 002-StaticWeb: ok', $combined);
        $this->assertStringContainsString('deploy-smoke: ok', $combined);
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
