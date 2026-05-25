<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * JIT compile coverage for runtime-resolved variable function calls (#1997, phase 2 of #56).
 *
 * MCJIT execute for this pattern is covered when {@see JITTest} runs the PHPT; full
 * execute may be gated on harness MCJIT stability (#2055). VM: {@see VMTest} +
 * variable_function_dynamic.phpt.
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
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
        $repo = dirname(__DIR__, 2);
        $phpt = __DIR__.'/cases/language/variable_function_dynamic_jit.phpt';
        $raw = (string) file_get_contents($phpt);
        if (!preg_match('/--FILE--\r?\n(.*?)\r?\n--EXPECT--/s', $raw, $match)) {
            $this->fail('Invalid PHPT: missing FILE section');
        }
        $code = $match[1];
        $this->assertNotSame('', trim($code));

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        $jit = realpath($repo.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $cmd = array_merge(
            LlvmToolchain::envPrefix($repo),
            [PHP_BINARY, $jit, '-l']
        );
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertStringNotContainsString('Variable function calls not yet supported', (string) $stderr);
    }
}
