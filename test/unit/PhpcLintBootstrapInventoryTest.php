<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/2208
 */
final class PhpcLintBootstrapInventoryTest extends TestCase
{
    public function testBootstrapInventoryFlagAccepted(): void
    {
        $exit = $this->runLint(['--bootstrap-inventory']);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $this->assertStringContainsString('bootstrap-inventory:', $exit['stdout']);
        $this->assertStringContainsString('scanned', $exit['stdout']);
    }

    public function testBootstrapInventoryCheckExitMatchesReport(): void
    {
        $report = $this->runLint(['--bootstrap-inventory']);
        $this->assertSame(0, $report['code'], $report['stderr']."\n".$report['stdout']);
        $hasIssues = 1 === preg_match('/(\d+) file\(s\) with unsupported syntax/', $report['stdout'], $m)
            && (int) $m[1] > 0;

        $check = $this->runLint(['--bootstrap-inventory', '--check']);
        $this->assertSame($hasIssues ? 1 : 0, $check['code'], $check['stderr']."\n".$check['stdout']);
    }

    public function testBootstrapInventoryFixturePathsLintClean(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        foreach (
            [
                'test/bootstrap-aot/nullsafe_method_call.php',
                'test/bootstrap-aot/assign_ref_alias.php',
            ] as $rel
        ) {
            $exit = $this->runLint([$repoRoot.'/'.$rel]);
            $this->assertSame(0, $exit['code'], $rel.': '.$exit['stderr']."\n".$exit['stdout']);
        }
    }

    public function testPhpcDelegatesBootstrapInventory(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'lint', '--bootstrap-inventory']
        );
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $this->assertStringContainsString('bootstrap-inventory:', $exit['stdout']);
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
