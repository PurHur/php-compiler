<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #19225 repro: try/catch/else on PHP 8.4 forward profile (#15817).
 */
final class TryCatchElseReproTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testIssueReproVmOutputsTryElseOnForwardProfile(): void
    {
        if (!CompilerVersion::supportsTryCatchElse()) {
            self::markTestSkipped('try/catch/else requires PHP_COMPILER_PROFILE=8.4');
        }

        $repro = $this->repoRoot.'/test/repro/maintainer_gap_try_catch_else_compile.php';
        $this->assertFileExists($repro);
        $this->assertSame('tryelse', $this->runVmFile($repro, ['PHP_COMPILER_PROFILE' => '8.4']));
    }

    public function testIssueReproReferenceProfileParseError(): void
    {
        if (CompilerVersion::supportsTryCatchElse()) {
            self::markTestSkipped('reference profile gate only when try/catch/else disabled');
        }

        $repro = $this->repoRoot.'/test/repro/maintainer_gap_try_catch_else_compile.php';
        $this->assertFileExists($repro);
        $exit = $this->runVmFileExit($repro, []);
        $this->assertSame(255, $exit);
    }

    /** @param array<string, string> $env */
    private function runVmFile(string $path, array $env): string
    {
        $bin = $this->repoRoot.'/bin/vm.php';
        $merged = $this->mergedEnv($env);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $bin, $path], $descriptor, $pipes, $this->repoRoot, $merged);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit);

        return $stdout !== false ? $stdout : '';
    }

    /** @param array<string, string> $env */
    private function runVmFileExit(string $path, array $env): int
    {
        $bin = $this->repoRoot.'/bin/vm.php';
        $merged = $this->mergedEnv($env);
        if ([] === $env) {
            unset($merged['PHP_COMPILER_PROFILE']);
        }
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, $bin, $path], $descriptor, $pipes, $this->repoRoot, $merged);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc);
    }

    /** @param array<string, string> $overrides */
    private function mergedEnv(array $overrides): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($overrides as $key => $value) {
            $env[$key] = $value;
        }

        return $env;
    }
}
