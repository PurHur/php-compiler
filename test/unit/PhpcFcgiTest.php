<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * phpc fcgi CLI (issue #2427).
 */
final class PhpcFcgiTest extends TestCase
{
    public function testHelpDocumentsProjectAndAdapter(): void
    {
        $result = $this->runPhpcFcgi(['--help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('--project', $result['stdout']);
        $this->assertStringContainsString('--listen', $result['stdout']);
        $this->assertStringContainsString('#173', $result['stdout']);
        $this->assertStringContainsString('009-FastCGIWeb', $result['stdout']);
    }

    public function testPhpcHelpListsFcgiSubcommand(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'help']);
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('phpc fcgi', $result['stdout']);
        $this->assertStringContainsString('--project', $result['stdout']);
    }

    public function testProjectMissingManifestExitsNonZero(): void
    {
        $tmpdir = sys_get_temp_dir().'/phpc-fcgi-'.bin2hex(random_bytes(4));
        mkdir($tmpdir);
        try {
            $result = $this->runPhpcFcgi(['--project', $tmpdir]);
            $this->assertSame(1, $result['exit']);
            $this->assertStringContainsString('phpc.json not found', $result['stderr']);
        } finally {
            @rmdir($tmpdir);
        }
    }

    /**
     * @param list<string> $fcgiArgs
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpcFcgi(array $fcgiArgs): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'fcgi', ...$fcgiArgs]);

        return $this->runCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{exit: int, stdout: string, stderr: string}
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
        $exit = proc_close($proc);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
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

        return [PHP_BINARY];
    }
}
