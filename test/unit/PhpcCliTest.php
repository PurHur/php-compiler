<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Unified phpc CLI dispatcher (issue #159, #294).
 */
final class PhpcCliTest extends TestCase
{
    public function testHelpListsSubcommands(): void
    {
        $result = $this->runPhpc(['help']);
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('phpc serve', $result['stdout']);
        $this->assertStringContainsString('phpc serve --aot', $result['stdout']);
        $this->assertStringContainsString('phpc run', $result['stdout']);
        $this->assertStringContainsString('phpc build', $result['stdout']);
        $this->assertStringContainsString('phpc build --project', $result['stdout']);
        $this->assertStringContainsString('--dry-run', $result['stdout']);
        $this->assertStringContainsString('--list-units', $result['stdout']);
        $this->assertStringContainsString('--print-includes', $result['stdout']);
        $this->assertStringContainsString('--verbose', $result['stdout']);
        $this->assertStringContainsString('PHPC_BUILD_VERBOSE', $result['stdout']);
        $this->assertStringContainsString('phpc deploy', $result['stdout']);
        $this->assertStringContainsString('phpc cgi', $result['stdout']);
        $this->assertStringContainsString('--from-build', $result['stdout']);
        $this->assertStringContainsString('phpc test', $result['stdout']);
        $this->assertStringContainsString('phpc lint', $result['stdout']);
        $this->assertStringContainsString('phpc init', $result['stdout']);
        $this->assertStringContainsString('phpc doctor', $result['stdout']);
        $this->assertStringContainsString('phpc validate-manifest', $result['stdout']);
        $this->assertStringContainsString('-q', $result['stdout']);
        $this->assertStringContainsString('$_GET', $result['stdout']);
    }

    public function testRunPopulatesScriptFilename(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $runner = $repoRoot.'/test/fixtures/web_echo_script_filename.php';
        $resolved = realpath($runner);
        $this->assertNotFalse($resolved);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'run', '-q', '', $runner]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $err !== false ? $err : '');
        $this->assertStringContainsString($resolved, $out !== false ? $out : '');
    }

    public function testRunSimpleWebWithQueryFlag(): void
    {
        $script = $this->repoRoot().'/examples/001-SimpleWeb/example.php';
        $result = $this->runPhpc(['run', '-q', 'name=Dev', $script]);
        $this->assertSuccessfulRun($result);
        $this->assertStringContainsString('Hello Dev', $result['stdout']);
    }

    public function testRunSimpleWebWithPostFlag(): void
    {
        $script = $this->repoRoot().'/examples/001-SimpleWeb/example.php';
        $result = $this->runPhpc(['run', '-p', 'name=Post', $script]);
        $this->assertSuccessfulRun($result);
        $this->assertStringContainsString('Hello Post', $result['stdout']);
    }

    public function testRunMissingScriptExitsNonZero(): void
    {
        $result = $this->runPhpc(['run']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('missing script.php', $result['stderr']);
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $phpcArgs arguments after bin/phpc.php
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runPhpc(array $phpcArgs): array
    {
        $repoRoot = $this->repoRoot();
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', ...$phpcArgs]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
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
     * @param array{exit: int, stdout: string, stderr: string} $result
     */
    private function assertSuccessfulRun(array $result): void
    {
        $this->assertSame(0, $result['exit'], trim($result['stderr']."\n".$result['stdout']));
        $this->assertDoesNotMatchRegularExpression(
            '/\b(Fatal error|Parse error|phpc run:)\b/',
            $result['stderr']
        );
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
