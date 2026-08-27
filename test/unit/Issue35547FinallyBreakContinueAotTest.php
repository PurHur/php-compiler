<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: break/continue inside try must run finally before leaving (#35547 / #25240).
 *
 * @group llvm
 */
final class Issue35547FinallyBreakContinueAotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — finally break/continue AOT test needs LLVM');
        }
    }

    public function testFinallyRunsOnContinueAndBreakInTry(): void
    {
        $repro = $this->repoRoot.'/test/repro/issue_25240_aot_finally_continue.php';
        $this->assertFileExists($repro);
        $expected = <<<'OUT'
B0F0F1B2F2
B0F0B1F1
OUT;
        $this->assertAotExecuteFile($repro, $expected);
    }

    private function assertAotExecuteFile(string $path, string $expected): void
    {
        $out = tempnam(sys_get_temp_dir(), 'phpc_35547_aot_');
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
        $compileOut = stream_get_contents($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileCode = proc_close($proc);
        $this->assertSame(0, $compileCode, "compile failed:\n".$compileOut.$compileErr);

        $run = proc_open([$out], $descriptorSpec, $runPipes, $this->repoRoot, $env);
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        $stderr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runCode = proc_close($run);
        @unlink($out);
        $this->assertSame(0, $runCode, "execute failed:\n".$stdout.$stderr);
        $this->assertSame($expected, rtrim($stdout, "\r\n"));
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
        $env['PHP_COMPILER_PROFILE'] = '8.4';
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
