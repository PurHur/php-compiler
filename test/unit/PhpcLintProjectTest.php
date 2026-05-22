<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/286
 */
final class PhpcLintProjectTest extends TestCase
{
    public function testLintAllHelloWorldExitsZero(): void
    {
        $dir = dirname(__DIR__, 2).'/examples/000-HelloWorld';
        $exit = $this->runLint(['--all', $dir]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    public function testLintProjectHelloWorldExitsZero(): void
    {
        $entry = dirname(__DIR__, 2).'/examples/000-HelloWorld/example.php';
        $exit = $this->runLint(['--project', $entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    public function testLintAllExamplesTreeExitsZero(): void
    {
        $dir = dirname(__DIR__, 2).'/examples';
        $exit = $this->runLint(['--all', $dir]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    public function testLintAllJsonAggregatesIssues(): void
    {
        $dir = dirname(__DIR__, 2).'/examples/000-HelloWorld';
        $exit = $this->runLint(['--json', '--all', $dir]);
        $this->assertSame(0, $exit['code']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('issues', $decoded);
        $this->assertSame([], $decoded['issues']);
    }

    public function testPhpcLintAllDelegatesToLintScript(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(
            self::phpCommand(),
            [$repoRoot.'/bin/phpc.php', 'lint', '--all', $repoRoot.'/examples/000-HelloWorld']
        );
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
    }

    public function testDirRelativeIncludeIsFollowed(): void
    {
        $entry = realpath(__DIR__.'/../compliance/cases/language/include_dir_literal/entry.php');
        $this->assertNotFalse($entry);
        $exit = $this->runLint(['--project', $entry]);
        $this->assertSame(0, $exit['code'], $exit['stderr']."\n".$exit['stdout']);
        $this->assertStringNotContainsString('dynamic include/require', $exit['stderr']);
    }

    /**
     * @see https://github.com/PurHur/php-compiler/issues/462
     */
    public function testLintMiniWebAppExitsZero(): void
    {
        $tree = dirname(__DIR__, 2).'/examples/003-MiniWebApp';
        $exit = $this->runLint(['--all', $tree]);
        $this->assertSame(
            0,
            $exit['code'],
            '003-MiniWebApp lint failed (see #539): '.$exit['stderr']."\n".$exit['stdout']
        );
        $this->assertStringNotContainsString(
            'dynamic include/require',
            $exit['stderr'],
            $exit['stderr']."\n".$exit['stdout']
        );
    }

    public function testDynamicIncludeEmitsWarning(): void
    {
        $tmp = sys_get_temp_dir().'/phpc_lint_proj_'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($tmp));
        file_put_contents($tmp.'/main.php', '<?php include $path; echo "ok";');
        try {
            $exit = $this->runLint(['--project', $tmp.'/main.php']);
            $this->assertSame(0, $exit['code']);
            $this->assertStringContainsString('dynamic include/require', $exit['stderr']);
        } finally {
            @unlink($tmp.'/main.php');
            @rmdir($tmp);
        }
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
