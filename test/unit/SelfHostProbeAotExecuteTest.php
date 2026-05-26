<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/008-SelfHostProbe presenter (#2407).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group selfhostprobe
 * @group selfhostprobe-aot-execute
 */
final class SelfHostProbeAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    protected function setUp(): void
    {
        $gate = getenv('SELFHOSTPROBE_AOT_SMOKE_GATE');
        if (false === $gate || '' === $gate || '1' !== $gate) {
            $this->markTestSkipped(
                'SELFHOSTPROBE_AOT_SMOKE_GATE=0 — set to 1 to run SelfHostProbe AOT execute tests (#2407)'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $example = $this->repoRoot.'/examples/008-SelfHostProbe/example.php';
        if (!is_file($example)) {
            $this->markTestSkipped('examples/008-SelfHostProbe missing (#2207)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $this->binary = sys_get_temp_dir().'/phpc-selfhostprobe-aot-'.bin2hex(random_bytes(4));
        $env = $this->baseEnv();
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '-o', $this->binary, $example],
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
        $this->assertSame(0, $exit, 'phpc build failed: '.substr($stderr, 0, 500));
        $this->assertFileExists($this->binary);
    }

    protected function tearDown(): void
    {
        if (isset($this->binary) && is_file($this->binary)) {
            @unlink($this->binary);
        }
    }

    public function testStdoutContainsSelfHostProbe(): void
    {
        $out = $this->runBinary([]);
        $this->assertStringContainsString('SelfHostProbe', $out);
        $this->assertStringContainsString('north-star2-verify', $out);
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
     * @param array<string, string> $extra
     */
    private function runBinary(array $extra): string
    {
        $env = array_merge($this->baseEnv(), $extra);
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
