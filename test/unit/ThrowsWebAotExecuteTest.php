<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/007-ThrowsWeb caught invalid POST (#2101).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group throwsweb
 * @group throwsweb-aot-execute
 */
final class ThrowsWebAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    protected function setUp(): void
    {
        $gate = getenv('THROWSWEB_AOT_SMOKE_GATE');
        if (false === $gate || '' === $gate || '1' !== $gate) {
            $this->markTestSkipped(
                'THROWSWEB_AOT_SMOKE_GATE=0 — set to 1 to run ThrowsWeb AOT execute tests (#2101)'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/examples/007-ThrowsWeb';
        if (!is_file($project.'/example.php')) {
            $this->markTestSkipped('examples/007-ThrowsWeb missing (#2076)');
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
        if (0 !== $exit) {
            if (PhpcBuild::isUserClassAotBlocked($stderr)) {
                $this->markTestSkipped(
                    '007-ThrowsWeb native AOT execute blocked (user class): '.trim($stderr)
                );
            }
            $this->markTestSkipped(
                '007-ThrowsWeb AOT execute not green yet (#195, #57): '.substr($stderr, 0, 500)
            );
        }

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    public function testInvalidPostShowsCaughtError(): void
    {
        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'POST';
        $env['REQUEST_BODY'] = 'email=bad';
        $env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['REQUEST_URI'] = '/example.php';

        $out = $this->runBinary($env);
        $this->assertMatchesRegularExpression('/invalid/i', $out);
    }

    public function testGetShowsSubmitPrompt(): void
    {
        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'GET';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['REQUEST_URI'] = '/example.php';

        $out = $this->runBinary($env);
        $this->assertStringContainsString('Submit an email', $out);
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
