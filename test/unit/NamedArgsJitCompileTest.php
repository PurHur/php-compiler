<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../LlvmToolchain.php';

/**
 * JIT lowering for named call arguments (#3777).
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class NamedArgsJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason . ' — named-args JIT compile test needs LLVM (#3777)');
        }
    }

    public function testNamedArgsModuleVerify(): void
    {
        $runtime = new Runtime();
        foreach (['named_args_jit.phpt', 'named_args_internal.phpt'] as $file) {
            $block = $runtime->parseAndCompile($this->fixtureCode($file), $file);
            $runtime->jitCompileBlock($block);
        }

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
    }

    public function testBinJitRunNamedUserFunctionWhenProbeOk(): void
    {
        if (!$this->jitProbeOk()) {
            $this->markTestSkipped('JIT MCJIT probe failed — bin/jit.php not runnable (#3777)');
        }
        $jit = realpath($this->repoRoot . '/bin/jit.php');
        if (false === $jit) {
            $this->markTestSkipped('bin/jit.php missing');
        }
        $code = $this->fixtureCode('named_args_jit.phpt');
        $env = $this->llvmProcessEnv();
        $cmd = array_merge(
            LlvmToolchain::envPrefix($this->repoRoot),
            [PHP_BINARY, $jit]
        );
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
        $combined = trim(($stdout !== false ? $stdout : '') . ($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('5', $combined);
    }

    private function fixtureCode(string $file): string
    {
        $path = $this->repoRoot . '/test/compliance/cases/language/' . $file;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        if (!preg_match('/--FILE--\s*\n(.*?)\n--EXPECT/s', $contents, $matches)) {
            $this->fail($file . ' FILE section missing');
        }

        return $matches[1];
    }

    private function jitProbeOk(): bool
    {
        $probe = $this->repoRoot . '/script/jit-runtime-probe.php';
        if (!\is_file($probe)) {
            return false;
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge(LlvmToolchain::envPrefix($this->repoRoot), [PHP_BINARY, $probe]),
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $this->llvmProcessEnv()
        );
        if (!\is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    /** @return array<string, string> */
    private function llvmProcessEnv(): array
    {
        $env = $_ENV;
        foreach ($_SERVER as $key => $value) {
            if (\is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
