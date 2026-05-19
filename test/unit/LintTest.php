<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/236
 */
final class LintTest extends TestCase
{
    public function testLintForeachReportsLineAndIssue(): void
    {
        $code = <<<'PHP'
<?php
foreach ([1, 2] as $x) {
    echo $x;
}
PHP;
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('unsupported', $exit['stdout']);
        $this->assertStringContainsString('#53', $exit['stdout']);
        $this->assertMatchesRegularExpression('/line \d+/', $exit['stdout']);
    }

    public function testLintCoalesceReportsIssue99(): void
    {
        $code = '<?php $a = $b ?? 1;';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('#99', $exit['stdout']);
    }

    public function testLintShiftAssignReportsIssue136(): void
    {
        $code = '<?php $x <<= 1;';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('#136', $exit['stdout']);
        $this->assertStringContainsString('Expr_BinaryOp_ShiftLeft', $exit['stdout']);
    }

    public function testLintYieldReportsIssue167(): void
    {
        $code = '<?php function f() { yield 1; }';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('#167', $exit['stdout']);
        $this->assertStringNotContainsString('#114', $exit['stdout']);
    }

    public function testLintCleanScriptExitsZero(): void
    {
        $code = '<?php echo "ok";';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
        $this->assertSame('', trim($exit['stdout']));
    }

    public function testLintJsonOutput(): void
    {
        $code = '<?php foreach ([1] as $v) {}';
        $exit = $this->runLint(['--json', '-r', $code]);
        $this->assertSame(1, $exit['code']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded['issues']);
        $this->assertArrayHasKey('line', $decoded['issues'][0]);
        $this->assertSame(53, $decoded['issues'][0]['issue']);
    }

    public function testPhpcLintDelegatesToLintScript(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'lint', '-r', '<?php foreach ([1] as $x) {}']);
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('#53', $exit['stdout']);
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
