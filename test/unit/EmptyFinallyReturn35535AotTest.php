<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: return through empty finally must not fail module verify (#35535 / re-#32371).
 *
 * @group llvm
 */
final class EmptyFinallyReturn35535AotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — empty-finally AOT test needs LLVM');
        }
    }

    public function testReturnInTryEmptyFinallyMatchesZend(): void
    {
        $src = <<<'PHP'
<?php
function f() {
  try { return 1; }
  finally { }
}
echo f(), "\n";
PHP;
        $this->assertAotSourceOutput($src, "1\n");
    }

    public function testReturnInCatchEmptyFinallyMatchesZend(): void
    {
        $src = <<<'PHP'
<?php
function f() {
  try { throw new Exception("x"); }
  catch (Exception $e) { return $e->getMessage(); }
  finally { }
}
echo f(), "\n";
PHP;
        $this->assertAotSourceOutput($src, "x\n");
    }

    public function testReproFileMatchesZend(): void
    {
        $repro = $this->repoRoot.'/test/repro/issue_35535_empty_finally_return_aot.php';
        $this->assertFileExists($repro);
        $this->assertAotFileOutput($repro, "1\nx\n");
    }

    private function assertAotSourceOutput(string $source, string $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_empty_fin_src_');
        $this->assertNotFalse($path);
        $path .= '.php';
        file_put_contents($path, $source);
        try {
            $this->assertAotFileOutput($path, $expected);
        } finally {
            @unlink($path);
        }
    }

    private function assertAotFileOutput(string $path, string $expected): void
    {
        $out = tempnam(sys_get_temp_dir(), 'phpc_empty_fin_aot_');
        $this->assertNotFalse($out);
        $env = $this->llvmEnv();
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $path],
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
        $code = proc_close($proc);
        $this->assertSame(0, $code, 'AOT compile failed: '.$stderr);
        $this->assertFileExists($out);
        $run = proc_open(
            [$out],
            $descriptorSpec,
            $runPipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runCode = proc_close($run);
        @unlink($out);
        $this->assertSame(0, $runCode, 'AOT run failed: '.$runErr);
        $this->assertSame($expected, $stdout);
    }

    /** @return array<string, string> */
    private function llvmEnv(): array
    {
        $env = $_ENV;
        foreach (['PATH', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'PHP_COMPILER_LLVM_PATH', 'PHP_COMPILER_PROFILE'] as $key) {
            $v = getenv($key);
            if (false !== $v && '' !== $v) {
                $env[$key] = $v;
            }
        }

        return $env;
    }
}
