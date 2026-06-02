<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * script/bench-compile.php JIT phase harness (issue #1898, #94).
 */
final class BenchCompileScriptTest extends TestCase
{
    public function testBenchCompileScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/bench-compile.php';
        $this->assertFileIsReadable($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('#94', $body);
        $this->assertStringContainsString('#153', $body);
        $this->assertStringContainsString('lazy ext/* JIT', $body);
        $this->assertStringContainsString('bench-compile-probe.php', $body);
        $this->assertFileIsReadable(dirname(__DIR__, 2).'/script/bench-compile-probe.php');
    }

    public function testBenchCompileScriptExitsZeroOnHelloWorld(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        if (!$this->llvmAvailable($repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available for JIT bench-compile');
        }
        if (!$this->jitProbeOk($repoRoot)) {
            $this->markTestSkipped('JIT MCJIT probe failed (bin/jit.php -l)');
        }

        $script = $repoRoot.'/script/bench-compile.php';
        $example = $repoRoot.'/examples/000-HelloWorld/example.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $script, $example],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $this->llvmEnv($repoRoot)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim(($stdout !== false ? $stdout : '')."\n".($stderr !== false ? $stderr : ''));
        $this->assertSame(0, $exit, $combined);
        $this->assertStringContainsString('Phase', $stdout !== false ? $stdout : '');
        $this->assertStringContainsString('parse', $stdout !== false ? $stdout : '');
        $this->assertStringContainsString('llvm emit', $stdout !== false ? $stdout : '');
    }

    public function testBenchCompileScriptSupportsJsonFlag(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        if (!$this->llvmAvailable($repoRoot)) {
            $this->markTestSkipped('LLVM 9 not available for JIT bench-compile');
        }
        if (!$this->jitProbeOk($repoRoot)) {
            $this->markTestSkipped('JIT MCJIT probe failed (bin/jit.php -l)');
        }

        $script = $repoRoot.'/script/bench-compile.php';
        $example = $repoRoot.'/examples/000-HelloWorld/example.php';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [PHP_BINARY, $script, '--json', $example],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $this->llvmEnv($repoRoot)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit);
        $decoded = json_decode($stdout !== false ? $stdout : '', true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('phases', $decoded);
        $this->assertArrayHasKey('parse', $decoded['phases']);
    }

    private function llvmAvailable(string $repoRoot): bool
    {
        require_once $repoRoot.'/script/bootstrap-lib.php';

        return null !== bootstrapResolveLlvmDir($repoRoot);
    }

    private function jitProbeOk(string $repoRoot): bool
    {
        $probe = $repoRoot.'/script/jit-runtime-probe.php';
        if (!is_file($probe)) {
            return false;
        }
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $probe], $descriptorSpec, $pipes, $repoRoot, $this->llvmEnv($repoRoot));
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }

    /**
     * @return array<string, string>
     */
    private function llvmEnv(string $repoRoot): array
    {
        require_once $repoRoot.'/script/bootstrap-lib.php';
        $llvmDir = bootstrapResolveLlvmDir($repoRoot);
        $this->assertNotNull($llvmDir);

        return bootstrapLlvmProcessEnv($llvmDir);
    }
}
