<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for class constant scalar expressions (#5394).
 *
 * Skipped when jit-runtime-probe or module verify fails (shared harness issue, #98).
 *
 * @group llvm
 */
final class ClassConstScalarExprJitExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — class const scalar expr JIT execute needs LLVM (#5394)');
        }
        if (!$this->jitRuntimeProbeOk()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#98, #5394)');
        }
    }

    public function testClassConstScalarExprMatchesVm(): void
    {
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $script = realpath($this->repoRoot.'/test/compliance/cases/language/class_const_scalar_expr_run.php');
        $this->assertNotFalse($script);

        $vm = realpath($this->repoRoot.'/bin/vm.php');
        $this->assertNotFalse($vm);

        $env = $this->llvmProcessEnv();
        $vmOut = $this->runScript([PHP_BINARY, $vm, $script], $env);
        $this->assertSame(0, $vmOut['exit'], 'VM: '.$vmOut['combined']);
        $expected = "3\nC\nC\n15\n";
        $this->assertSame($expected, $vmOut['stdout']);

        $jitOut = $this->runScript(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit, $script]),
            $env
        );
        $this->assertSame(0, $jitOut['exit'], 'JIT: '.$jitOut['combined']);
        $this->assertSame($vmOut['stdout'], $jitOut['stdout']);
    }

    /** @param list<string> $cmd */
    private function runScript(array $cmd, array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => $exit,
            'stdout' => false !== $stdout ? $stdout : '',
            'combined' => trim((false !== $stdout ? $stdout : '').(false !== $stderr ? $stderr : '')),
        ];
    }

    private function jitRuntimeProbeOk(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            return false;
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $probe], $descriptorSpec, $pipes, $this->repoRoot);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return 0 === $code && is_string($stdout) && str_contains($stdout, 'jit-runtime-probe OK');
    }

    /** @return array<string, string> */
    private function llvmProcessEnv(): array
    {
        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
