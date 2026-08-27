<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: continue in try must still run finally (#35547 / re-#25240).
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
        $src = <<<'PHP'
<?php
$out = '';
for ($i = 0; $i < 3; $i++) {
    try {
        if ($i === 1) {
            continue;
        }
        $out .= 'B'.$i;
    } finally {
        $out .= 'F'.$i;
    }
}
echo $out, "\n";
PHP;
        $this->assertAotSourceOutput($src, "B0F0F1B2F2\n");
    }

    public function testReproFileMatchesZend(): void
    {
        $repro = $this->repoRoot.'/test/repro/issue_35547_aot_finally_continue.php';
        $this->assertFileExists($repro);
        $this->assertAotFileOutput($repro, "B0F0F1B2F2\n");
    }

    private function assertAotSourceOutput(string $source, string $expected): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_fin_leave_src_');
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
        $out = tempnam(sys_get_temp_dir(), 'phpc_fin_leave_aot_');
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
        $run = proc_open([$out], $descriptorSpec, $runPipes, $this->repoRoot, $env);
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
        $env['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = sys_get_temp_dir().'/phpc-fin-leave-'.getmypid();

        return $env;
    }
}
