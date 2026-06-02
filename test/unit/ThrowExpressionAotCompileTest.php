<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT compile + execute: throw expressions with sequential try/catch (#4041).
 *
 * @group llvm
 */
final class ThrowExpressionAotCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — throw expression AOT compile test needs LLVM');
        }
    }

    public function testThrowExpressionPhptCompilesForAot(): void
    {
        $phpt = $this->repoRoot.'/test/compliance/cases/language/throw_expression.phpt';
        $raw = file_get_contents($phpt);
        $this->assertIsString($raw);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT--/s', $raw, $m)) {
            $this->fail('PHPT missing FILE section: '.$phpt);
        }
        $this->assertAotCompileExitZero($m[1]);
    }

    public function testSequentialTryCatchExecutesAfterThrow(): void
    {
        $this->assertAotExecuteOutput(<<<'PHP'
<?php
class Ex {}
try { throw new Ex(); } catch (Ex $e) { echo "caught\n"; }
PHP
            , "caught\n");
    }

    private function assertAotCompileExitZero(string $code): void
    {
        $src = tempnam(sys_get_temp_dir(), 'phpc_throw_expr_');
        $this->assertNotFalse($src);
        file_put_contents($src, $code);
        $out = $src.'_aot';
        $env = $this->llvmEnv();
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $src],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($src);
        @unlink($out);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));
    }

    private function assertAotExecuteOutput(string $code, string $expected): void
    {
        $src = tempnam(sys_get_temp_dir(), 'phpc_throw_expr_');
        $this->assertNotFalse($src);
        file_put_contents($src, $code);
        $out = $src.'_aot';
        $env = $this->llvmEnv();
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $src],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        $procRun = proc_open([$out], $descriptorSpec, $runPipes, $this->repoRoot);
        $this->assertIsResource($procRun);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runExit = proc_close($procRun);
        @unlink($src);
        @unlink($out);
        $this->assertSame(0, $runExit);
        $this->assertSame($expected, $stdout !== false ? $stdout : '');
    }

    /** @return array<string, string> */
    private function llvmEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
