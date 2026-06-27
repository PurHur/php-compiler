<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * MCJIT execute for PHP 8.3 typed class constants (#4900).
 *
 * @group llvm
 */
final class JitTypedClassConstExecuteTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        LlvmToolchain::applyCurrentProcessEnv($this->repoRoot);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — typed class const JIT execute needs LLVM (#4900)');
        }
        if (!$this->jitRuntimeProbeOk()) {
            $this->markTestSkipped('jit-runtime-probe failed — MCJIT execute unavailable (#98, #4900)');
        }
    }

    /**
     * @dataProvider typedClassConstPhptProvider
     */
    public function testTypedClassConstMatchesVm(string $fixture): void
    {
        if (!CompilerVersion::supportsTypedClassConstants()) {
            $this->markTestSkipped('typed class constants require CompilerVersion 8.4.0+');
        }
        $jit = realpath($this->repoRoot.'/bin/jit.php');
        $this->assertNotFalse($jit);
        $vm = realpath($this->repoRoot.'/bin/vm.php');
        $this->assertNotFalse($vm);
        $code = $this->phptFixtureCode($fixture);
        $env = $this->llvmProcessEnv();
        $vmOut = $this->runScript([PHP_BINARY, $vm], $env, $code);
        $this->assertSame(0, $vmOut['exit'], 'VM: '.$vmOut['combined']);
        $jitOut = $this->runScript(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $jit]),
            $env,
            $code
        );
        $this->assertSame(0, $jitOut['exit'], 'JIT: '.$jitOut['combined']);
        $this->assertSame($vmOut['stdout'], $jitOut['stdout']);
    }

    public static function typedClassConstPhptProvider(): iterable
    {
        foreach (
            [
                'typed_class_const.phpt',
                'typed_class_const_float_int.phpt',
                'typed_class_constant.phpt',
                'interface_typed_const.phpt',
            ] as $fixture
        ) {
            yield $fixture => [$fixture];
        }
    }

    /** @param list<string> $cmd */
    private function runScript(array $cmd, array $env, string $stdin): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $stdin);
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
        $cmd = array_merge(
            LlvmToolchain::envPrefix($this->repoRoot),
            [PHP_BINARY, $probe]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $this->repoRoot, $this->llvmProcessEnv());
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
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }

    private function phptFixtureCode(string $file): string
    {
        $path = $this->repoRoot.'/test/compliance/cases/language/'.$file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--(?:ENV|EXPECT)/s', $contents, $matches)) {
            $this->fail($file.' FILE section missing');
        }

        return $matches[1];
    }
}
