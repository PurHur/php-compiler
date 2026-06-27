<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * MCJIT execute for property set hooks with builtin calls in hook body (#4025).
 *
 * @group llvm
 */
final class PropertyHookJitExecuteTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 3);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped(
                LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available'
            );
        }
        if (!$this->jitRuntimeProbeOk()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#98, #4025)');
        }
    }

    public function testPropertyHookSetWithStrContainsMatchesVmOutput(): void
    {
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        if (false === $jit) {
            $this->markTestSkipped('bin/jit.php missing');
        }
        $script = realpath($this->repoRoot.'/test/repro-maintainer/property_hook_jit.php');
        if (false === $script) {
            $this->markTestSkipped('property_hook_jit.php repro missing');
        }
        $env = $this->llvmProcessEnv();
        $cmd = array_merge(
            LlvmToolchain::envPrefix($this->repoRoot),
            [PHP_BINARY, $jit, $script]
        );
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
        $combined = trim(($stdout !== false ? $stdout : '').($stderr !== false ? $stderr : ''));
        if (0 !== $exit) {
            $this->fail('bin/jit.php property hook repro failed (exit '.$exit.'): '.$combined);
        }
        $this->assertStringContainsString('reject', $combined);
        $this->assertStringContainsString('a@b.c', $combined);
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
