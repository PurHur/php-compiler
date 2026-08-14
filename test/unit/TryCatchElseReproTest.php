<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Issue #31159 repro: try/catch/else is a Zend Parse error on php-src-strict (including PROFILE=8.4).
 */
final class TryCatchElseReproTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    /** @return list<array{0: array<string, string>}> */
    public static function rejectEnvProvider(): array
    {
        return [
            'unset' => [[]],
            '8.4' => [['PHP_COMPILER_PROFILE' => '8.4']],
            '8.5' => [['PHP_COMPILER_PROFILE' => '8.5']],
        ];
    }

    /** @dataProvider rejectEnvProvider */
    public function testIssueReproParseErrorOnPhpSrcStrict(array $env): void
    {
        $repro = $this->repoRoot.'/test/repro/issue_31159_try_catch_else.php';
        $this->assertFileExists($repro);
        [$exit, $stderr] = $this->runVmFile($repro, $env);
        $this->assertSame(255, $exit);
        $this->assertStringContainsString('unexpected token "else"', $stderr);
        $this->assertMatchesRegularExpression('/Parse error/i', $stderr);
    }

    public function testOrdinaryTryCatchFinallyStillRuns(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'phpc_tce_ok_');
        $this->assertNotFalse($src);
        file_put_contents($src, <<<'PHP'
<?php
try { echo "t"; } catch (Exception $e) { echo "c"; } finally { echo "f"; }
echo "\n";
PHP
        );
        [$exit, $stderr, $stdout] = $this->runVmFile($src, []);
        @unlink($src);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("tf\n", $stdout);
    }

    /**
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string}
     */
    private function runVmFile(string $path, array $env): array
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
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$exit, $stderr !== false ? $stderr : '', $stdout !== false ? $stdout : ''];
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
