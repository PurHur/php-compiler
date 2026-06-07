<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for UnitEnum::cases() (#6487).
 *
 * Spawns bin/jit.php in a child process (issue #98 — no in-process LLVM preload).
 *
 * @group llvm
 */
final class EnumCasesJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available — UnitEnum::cases() JIT execute needs MCJIT (#6487)');
        }
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

    public function testUnitEnumCasesJitExecute(): void
    {
        $script = $this->writeProbeScript('enum-cases-jit-exec', <<<'PHP'
<?php
enum U { case A; case B; }
echo count(U::cases()), "\n";
echo U::cases()[0]->name, "\n";
PHP
        );
        $output = $this->runJitScript($script);
        @unlink($script);
        $this->assertSame("2\nA\n", $output);
    }

    public function testUnitEnumCasesIdentityWithConstFetch(): void
    {
        $script = $this->writeProbeScript('enum-cases-identity', <<<'PHP'
<?php
enum U { case A; case B; }
echo (U::cases()[0] === U::A) ? "1\n" : "0\n";
PHP
        );
        $output = $this->runJitScript($script);
        @unlink($script);
        $this->assertSame("1\n", $output);
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
