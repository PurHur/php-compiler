<?php

declare(strict_types=1);

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * Inventory argv spine: `global $argv` + isset must not hit Unknown Operand\Variable (#12036).
 *
 * @group llvm
 * @group jit
 */
final class JitArgvGlobalAliasTest extends TestCase
{
    public function testGlobalArgvIssetCompilesWithoutUnknownVariable(): void
    {
        $this->skipUnlessLlvmReady();
        $code = <<<'PHP'
<?php
function inventory_argv_isset_probe(): bool
{
    global $argv;

    return isset($argv) && is_array($argv);
}
PHP;
        $stderr = $this->runJitProbeInSubprocess($code);
        self::assertStringNotContainsString('Unknown variable referenced', $stderr);
    }

    private function skipUnlessLlvmReady(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                LlvmToolchain::readyFailureReason()
                ?? 'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private function runJitProbeInSubprocess(string $code): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $phpPath = tempnam(sys_get_temp_dir(), 'jit_argv_src_').'.php';
        file_put_contents($phpPath, $code);

        $probePhp = tempnam(sys_get_temp_dir(), 'jit_argv_probe_').'.php';
        file_put_contents($probePhp, <<<'PROBE'
<?php
require 'test/bootstrap.php';
PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
$source = $argv[1];
$code = file_get_contents($source);
putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
putenv('PHP_COMPILER_M3_COMPILE_DRIVER_MAIN=1');
putenv('PHP_COMPILER_SELFHOST_AOT=1');
$runtime = new PHPCompiler\Runtime(PHPCompiler\Runtime::MODE_AOT);
$block = $runtime->parseAndCompile($code, basename($source));
try {
    $runtime->jit($block);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PROBE
        );

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $argv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $probePhp, $phpPath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($argv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($phpPath);
        @unlink($probePhp);

        return $stderr;
    }
}
