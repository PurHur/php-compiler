<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * JIT compile coverage for runtime-resolved variable function calls (#1997, phase 2 of #56).
 *
 * MCJIT execute: {@see JITTest} + {@see VariableFunctionCallTest} in-process probes (#2055).
 * VM: {@see VMTest} + variable_function_dynamic.phpt.
 *
 * @group llvm
 * @group jit
 */
final class VariableFunctionDynamicJITTest extends TestCase
{
    /**
     * @group llvm
     * @group jit
     */
    public function testDynamicVariableFunctionJitLint(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($repo)) {
            $this->markTestSkipped('LLVM 9 not available');
        }
        $phpt = __DIR__.'/cases/language/variable_function_dynamic_jit.phpt';
        $raw = (string) file_get_contents($phpt);
        if (!preg_match('/--FILE--\r?\n(.*?)\r?\n--EXPECT--/s', $raw, $match)) {
            $this->fail('Invalid PHPT: missing FILE section');
        }
        $code = $match[1];
        $this->assertNotSame('', trim($code));

        $stderr = $this->runJitCompileProbe($repo, $code);
        $this->assertStringNotContainsString('Variable function calls not yet supported', $stderr);
    }

    private function runJitCompileProbe(string $repo, string $code): string
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'phpc_var_fn_jit_');
        $this->assertNotFalse($sourcePath);
        $phpPath = $sourcePath.'.php';
        rename($sourcePath, $phpPath);
        file_put_contents($phpPath, $code);

        $probePath = tempnam(sys_get_temp_dir(), 'phpc_var_fn_probe_');
        $this->assertNotFalse($probePath);
        $probePhp = $probePath.'.php';
        rename($probePath, $probePhp);
        file_put_contents($probePhp, <<<'PROBE'
<?php
require 'test/bootstrap.php';
PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
$source = $argv[1];
$code = file_get_contents($source);
$runtime = new PHPCompiler\Runtime();
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
        unset($env['PHP_COMPILER_SKIP_LLVM_PRELOAD']);
        LlvmToolchain::applyProcessEnv($env, $repo);
        $argv = array_merge(
            LlvmToolchain::envPrefix($repo),
            [PHP_BINARY, $probePhp, $phpPath]
        );
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($phpPath);
        @unlink($probePhp);

        return (string) $stderr;
    }
}
