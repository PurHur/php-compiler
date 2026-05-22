<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Lint\UnsupportedRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/484
 */
final class PhpcLintJsonTest extends TestCase
{
    public function testLintJsonIncludesIssueUrlForRegistryMapping(): void
    {
        $code = '<?php function f() { yield 1; }';
        $exit = $this->runLint(['--json', '-r', $code]);
        $this->assertSame(1, $exit['code']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['issues']);
        $issue = $decoded['issues'][0];
        $this->assertSame(167, $issue['issue']);
        $this->assertSame(
            UnsupportedRegistry::issueUrl(167),
            $issue['issue_url']
        );
        $this->assertStringContainsString('issues/167', $exit['stdout']);
    }

    public function testPhpcLintJsonIncludesIssueUrl(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'lint', '--json', '-r', '<?php function f() { yield 1; }']
        );
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('issues/167', $exit['stdout']);
    }

    /**
     * @param list<string> $lintArgs arguments after bin/lint.php
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLint(array $lintArgs): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/lint.php'], $lintArgs);

        return $this->runCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }

        return $cmd;
    }
}
