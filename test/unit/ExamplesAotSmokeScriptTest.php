<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * script/examples-aot-smoke.sh CLI AOT harness (issue #667).
 */
final class ExamplesAotSmokeScriptTest extends TestCase
{
    public function testExamplesAotSmokeScriptSkipsWhenLlvmMissing(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/examples-aot-smoke.sh';
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

    public function testExamplesAotSmokeScriptDocumentsMiniWebAppSkip(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-aot-smoke.sh');
        $this->assertStringContainsString('003-MiniWebApp', $body);
        $this->assertStringContainsString('#568', $body);
        $this->assertStringContainsString('.phpc/smoke', $body);
    }

    public function testExamplesAotSmokeScriptPassesWhenLlvmReady(): void
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

        $script = $repoRoot.'/script/examples-aot-smoke.sh';
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
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('examples-aot-smoke: 000-HelloWorld: ok', $combined);
        $this->assertStringContainsString('examples-aot-smoke: 001-SimpleWeb: ok', $combined);
        $this->assertStringContainsString('examples-aot-smoke: 002-StaticWeb: ok', $combined);
        $this->assertStringContainsString('examples-aot-smoke: 004-ApiJson: ok', $combined);
        $this->assertStringContainsString('examples-aot-smoke: ok', $combined);
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
