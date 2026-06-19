<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ReadonlyFunctionRejector;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/236
 */
final class LintTest extends TestCase
{
    public function testLintForeachAccepted(): void
    {
        $code = <<<'PHP'
<?php
foreach ([1, 2] as $x) {
    echo $x;
}
PHP;
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
    }

    public function testLintReadonlyFunctionRejected(): void
    {
        $code = <<<'PHP'
<?php
readonly function f(): void {}
f();
PHP;
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(1, $exit['code'], $exit['stdout']);
        $this->assertStringContainsString(ReadonlyFunctionRejector::MESSAGE, $exit['stdout']);
    }

    public function testLintCoalesceAccepted(): void
    {
        $code = '<?php $a = $b ?? 1;';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
    }

    public function testLintCoalesceAssignAccepted(): void
    {
        $code = '<?php $a = null; $a ??= "default"; echo $a;';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_AssignOp_Coalesce', $exit['stdout']);
    }

    public function testLintThrowExpressionAccepted(): void
    {
        $code = '<?php $e = $x; $y = (throw $e);';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_Throw', $exit['stdout']);
    }

    public function testLintYieldFromReportsIssue167(): void
    {
        $code = '<?php function f() { yield from [1, 2]; }';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(1, $exit['code']);
        $this->assertStringContainsString('Expr_YieldFrom', $exit['stdout']);
        $this->assertStringContainsString('#167', $exit['stdout']);
    }

    public function testLintNullsafePropertyAccepted(): void
    {
        $code = '<?php class C { public int $x = 1; } $c = null; $c?->x;';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
    }

    public function testLintAssignOpConcatAccepted(): void
    {
        $exit = $this->runLint(['-r', '<?php $s = "a"; $s .= "b"; echo $s;']);
        $this->assertSame(0, $exit['code']);
    }

    public function testLintAssignOpPlusAccepted(): void
    {
        $exit = $this->runLint(['-r', '<?php $n = 1; $n += 2; echo $n;']);
        $this->assertSame(0, $exit['code']);
    }

    public function testLintShiftAssignAccepted(): void
    {
        $exit = $this->runLint(['-r', '<?php $x = 1; $x <<= 2; echo $x;']);
        $this->assertSame(0, $exit['code']);
    }

    public function testLintYieldReportsIssue167(): void
    {
        $code = '<?php function f() { yield 1; }';
        $exit = $this->runLint(['-r', $code]);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('#167', $exit['stdout']);
    }

    public function testLintPreIncExitsZero(): void
    {
        $exit = $this->runLint(['-r', '++$i;']);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_PreInc', $exit['stdout']);
    }

    public function testLintPostIncExitsZero(): void
    {
        $exit = $this->runLint(['-r', '$i++;']);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_PostInc', $exit['stdout']);
    }

    public function testLintShortListDestructuringExitsZero(): void
    {
        $exit = $this->runLint(['-r', '[$a,$b]=["x","y"]; echo $a, $b;']);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_List', $exit['stdout']);
    }

    public function testLintListFunctionDestructuringExitsZero(): void
    {
        $exit = $this->runLint(['-r', 'list($a,$b)=array(1,2); echo $a, $b;']);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_List', $exit['stdout']);
    }

    public function testLintKeyedListDestructuringExitsZero(): void
    {
        $exit = $this->runLint(['-r', '["a" => $x, "b" => $y] = ["a" => 1, "b" => 2]; echo $x, $y;']);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('Expr_List', $exit['stdout']);
    }

    public function testLintSwitchWithLiteralCasesExitsZero(): void
    {
        $exit = $this->runLint(['-r', 'switch (1) { case 1: echo 1; break; default: echo 0; }']);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('#96', $exit['stdout']);
        $this->assertStringNotContainsString('Stmt_Switch', $exit['stdout']);
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
        $code = '<?php function f() { yield 1; }';
        $exit = $this->runLint(['--json', '-r', $code]);
        $this->assertSame(0, $exit['code']);
        $decoded = json_decode($exit['stdout'], true);
        $this->assertIsArray($decoded);
        $this->assertSame([], $decoded['issues'] ?? null);
    }

    public function testPhpcLintDelegatesToLintScript(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = array_merge(self::phpCommand(), [$repoRoot.'/bin/phpc.php', 'lint', '-r', '<?php function f() { yield 1; }']);
        $exit = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $exit['code']);
        $this->assertStringNotContainsString('#167', $exit['stdout']);
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
