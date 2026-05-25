<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * phpc test --bootstrap subset (issue #1961).
 */
final class PhpcTestBootstrapTest extends TestCase
{
    public function testBootstrapScriptExistsAndIsExecutable(): void
    {
        $script = $this->repoRoot().'/script/bootstrap-test-subset.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
    }

    public function testBootstrapScriptSourcesCiCommonAndRunsInventoryCheck(): void
    {
        $body = (string) file_get_contents($this->repoRoot().'/script/bootstrap-test-subset.sh');
        $this->assertStringContainsString('ci-common.sh', $body);
        $this->assertStringContainsString('ci_ensure_generated_doc script/bootstrap-inventory.php', $body);
        $this->assertStringContainsString('ci_run_selfhost_spine_count_sync_check', $body);
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_VM_SMOKE', $body);
        $this->assertStringContainsString('ci_run_bootstrap_lib_spine_vm_smoke', $body);
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict', $body);
    }

    public function testPhpcTestBootstrapDispatchesToSubsetScript(): void
    {
        $body = (string) file_get_contents($this->repoRoot().'/bin/phpc.php');
        $this->assertStringContainsString('bootstrap-test-subset.sh', $body);
        $this->assertStringContainsString('--bootstrap-strict', $body);
        $this->assertStringContainsString('--bootstrap cannot be combined with --fast', $body);
    }

    public function testPhpcTestBootstrapAndFastAreMutuallyExclusive(): void
    {
        $result = $this->runPhpc(['test', '--bootstrap', '--fast']);
        $this->assertNotSame(0, $result['exit']);
        $this->assertStringContainsString('--bootstrap cannot be combined with --fast', $result['stderr']);
    }

    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string> $phpcArgs
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
