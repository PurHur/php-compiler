<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: break/continue in try must run finally (Zend ZEND_BRK/ZEND_CONT, #35547 / #25240).
 *
 * @group llvm
 */
final class FinallyBreakContinue35547AotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — finally leave AOT test needs LLVM');
        }
    }

    public function testContinueInTryFinallyMatchesZend(): void
    {
        $this->assertAotFileOutput(
            $this->repoRoot.'/test/repro/issue_25240_aot_finally_continue.php',
            "B0F0F1B2F2\n"
        );
    }

    public function testBreakInTryFinallyMatchesZend(): void
    {
        $this->assertAotFileOutput(
            $this->repoRoot.'/test/repro/issue_25240_aot_finally_break.php',
            "B0F0B1F1\n"
        );
    }

    public function testNestedContinueRunsOuterFinally(): void
    {
        $this->assertAotFileOutput(
            $this->repoRoot.'/test/repro/issue_25240_aot_finally_nested.php',
            "B0I0O0I1O1B2I2O2\n"
        );
    }

    public function testSequentialTryFinallyLeaveIndependent(): void
    {
        $this->assertAotFileOutput(
            $this->repoRoot.'/test/repro/issue_25240_aot_finally_cont_break.php',
            "B0F0F1B2F2\nB0F0B1F1\n"
        );
    }

    public function testComplianceCaseMatchesZend(): void
    {
        $this->assertAotFileOutput(
            $this->repoRoot.'/test/repro/issue_25240_aot_finally_compliance.php',
            "B0F0F1B2F2\nB0F0B1F1\nB0I0O0I1O1B2I2O2\n"
        );
    }

    private function assertAotFileOutput(string $path, string $expected): void
    {
        $this->assertFileExists($path);
        $out = tempnam(sys_get_temp_dir(), 'phpc_finally_leave_aot_');
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
