<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for $class::CONST when $class is a runtime string (#4095).
 *
 * @group llvm
 */
final class ClassConstVariableClassJitTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — variable class const JIT needs LLVM (#4095)');
        }
        if (!$this->jitRuntimeProbeOk()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#4095)');
        }
    }

    public function testVariableClassConstFetchMatchesVm(): void
    {
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $vm = realpath($this->repoRoot.'/bin/vm.php');
        $this->assertNotFalse($jit);
        $this->assertNotFalse($vm);

        $code = <<<'PHP'
<?php
class C {
    public const X = 99;
}
$cls = 'C';
echo $cls::X, "\n";
PHP;

        $env = $this->llvmProcessEnv();
        $vmOut = $this->runPhpCode([PHP_BINARY, $vm], $code, $env);
        $this->assertSame(0, $vmOut['exit'], 'VM: '.$vmOut['combined']);
        $this->assertSame("99\n", $vmOut['stdout']);

        $jitOut = $this->runPhpCode(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit]),
            $code,
            $env
        );
        $this->assertSame(0, $jitOut['exit'], 'JIT: '.$jitOut['combined']);
        $this->assertSame($vmOut['stdout'], $jitOut['stdout']);
    }

    public function testUnknownClassThrowsErrorLikeVm(): void
    {
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $vm = realpath($this->repoRoot.'/bin/vm.php');
        $this->assertNotFalse($jit);
        $this->assertNotFalse($vm);

        $code = <<<'PHP'
<?php
$cls = 'MissingClass';
try {
    echo $cls::X;
} catch (\Error $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
PHP;

        $env = $this->llvmProcessEnv();
        $vmOut = $this->runPhpCode([PHP_BINARY, $vm], $code, $env);
        $this->assertSame(0, $vmOut['exit'], 'VM: '.$vmOut['combined']);
        $this->assertStringContainsString('Error:', $vmOut['stdout']);

        $jitOut = $this->runPhpCode(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit]),
            $code,
            $env
        );
        $this->assertSame(0, $jitOut['exit'], 'JIT: '.$jitOut['combined']);
        $this->assertStringContainsString('Error:', $jitOut['stdout']);
    }

    /** @param list<string> $cmd */
    private function runPhpCode(array $cmd, string $code, array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
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
