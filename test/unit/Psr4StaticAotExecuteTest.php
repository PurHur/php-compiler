<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT project build with phpc.json autoload.psr-4 only (no includes[]) — issue #1762.
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class Psr4StaticAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/test/fixtures/aot/projects/psr4_static';
        if (!is_file($project.'/phpc.json')) {
            $this->markTestSkipped('psr4_static fixture missing');
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
        $this->assertSame(0, $exit, 'phpc build --project psr4_static failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    public function testGreeterPrintsHi(): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$this->binary], $descriptorSpec, $pipes, $this->repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit);
        $this->assertSame('hi', rtrim(false !== $stdout ? $stdout : ''));
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'PHP_COMPILER_LLVM_PATH', 'PHP_COMPILER_EXT_DIR'] as $key) {
            $value = getenv($key);
            if (false !== $value && '' !== $value) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
