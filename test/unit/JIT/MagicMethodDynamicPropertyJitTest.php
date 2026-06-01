<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\JIT;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * MCJIT: __get via dynamic property name $obj->$name (#4066).
 *
 * @group llvm
 */
final class MagicMethodDynamicPropertyJitTest extends TestCase
{
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
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable');
        }
    }

    public function testDynamicMagicGetWithToStringMatchesZendOutput(): void
    {
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $script = realpath($this->repoRoot.'/test/repro-maintainer/issue4066_dynamic_prop.php');
        $this->assertNotFalse($jit);
        $this->assertNotFalse($script);
        $env = LlvmToolchain::envPrefix($this->repoRoot);
        $cmd = array_merge($env, [PHP_BINARY, $jit, $script]);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $combined = trim(($stdout !== false ? $stdout : '').($stderr !== false ? $stderr : ''));
        if (0 !== $exit) {
            $this->fail('bin/jit.php issue4066 repro failed (exit '.$exit.'): '.$combined);
        }
        $this->assertSame('1box', $combined);
    }

    private function jitRuntimeProbeOk(): bool
    {
        $probe = $this->repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            return false;
        }
        $proc = proc_open([PHP_BINARY, $probe], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }
}
