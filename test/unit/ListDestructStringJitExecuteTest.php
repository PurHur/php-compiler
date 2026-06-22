<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for list destructuring from string RHS — NULL slots (#10486, #4531).
 *
 * Spawns bin/jit.php in a child process (issue #98 — no in-process LLVM preload).
 *
 * @group llvm
 */
final class ListDestructStringJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — list destruct string JIT execute needs LLVM (#4531)');
        }
    }

    public function testStringRhsLeavesTargetsNull(): void
    {
        $script = $this->writeProbeScript('list-destruct-string-jit-exec', <<<'PHP'
<?php
list($a, $b) = 'ab';
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
[$x] = 'xy';
echo "x=", var_export($x, true), "\n";
[[ $y ]] = 'z';
echo "y=", var_export($y, true), "\n";
PHP
        );
        $output = $this->runJitScript($script);
        @unlink($script);
        $this->assertSame("a=NULL b=NULL\nx=NULL\ny=NULL\n", $output);
    }

    private function writeProbeScript(string $basename, string $code): string
    {
        $dir = $this->repoRoot.'/var';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->fail('Could not create var/ for JIT execute probe');
        }
        $script = $dir.'/'.$basename.'-'.getmypid().'.php';
        file_put_contents($script, $code);

        return $script;
    }

    private function runJitScript(string $scriptPath): string
    {
        $llvmDir = LlvmToolchain::resolveDir($this->repoRoot);
        $this->assertNotNull($llvmDir);

        $bash = <<<'BASH'
set -euo pipefail
ROOT=%s
SCRIPT=%s
source "$ROOT/script/php-env.sh"
export PHP_COMPILER_LLVM_PATH=%s
export LD_LIBRARY_PATH="%s${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
unset PHP_COMPILER_SKIP_LLVM_PRELOAD
"$PHP_BIN" "${PHP_OPTS[@]}" "$ROOT/bin/jit.php" "$SCRIPT"
BASH;

        $command = sprintf(
            $bash,
            escapeshellarg($this->repoRoot),
            escapeshellarg($scriptPath),
            escapeshellarg($llvmDir),
            $llvmDir
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['bash', '-lc', $command], $descriptorSpec, $pipes, $this->repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr."\n".(string) $stdout));

        return (string) $stdout;
    }
}
