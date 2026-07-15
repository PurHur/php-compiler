<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * User-script AOT: try/catch/else runs else on normal try completion (#19128, #19148).
 *
 * @group llvm
 */
final class TryCatchElseAotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!CompilerVersion::supportsTryCatchElse()) {
            $this->markTestSkipped('try/catch/else requires PHP_COMPILER_PROFILE=8.4');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — try/catch/else AOT test needs LLVM');
        }
    }

    public function testTryCatchElseAotPhptNormalPath(): void
    {
        $pairs = $this->loadAotPhptFileExpectPairs();
        $this->assertAotExecuteOutput($pairs[0]['file'], $pairs[0]['expect']);
    }

    public function testTryCatchElseAotPhptCatchPath(): void
    {
        $pairs = $this->loadAotPhptFileExpectPairs();
        $this->assertAotExecuteOutput($pairs[1]['file'], $pairs[1]['expect']);
    }

    public function testMaintainerGapReproExecutesTryElse(): void
    {
        $repro = $this->repoRoot.'/test/repro/maintainer_gap_try_catch_else_basic.php';
        $this->assertFileExists($repro);
        $this->assertAotExecuteFile($repro, 'tryelse');
    }

    /**
     * @return list<array{file: string, expect: string}>
     */
    private function loadAotPhptFileExpectPairs(): array
    {
        $phpt = $this->repoRoot.'/test/compliance/cases/language/try_catch_else_aot.phpt';
        $raw = file_get_contents($phpt);
        $this->assertIsString($raw);
        preg_match_all('/--FILE--\s*\n(.*?)\n--EXPECT--\s*\n(.*?)(?=\n--|\z)/s', $raw, $matches, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(2, \count($matches), 'try_catch_else_aot.phpt must define normal + catch FILE/EXPECT pairs');

        $pairs = [];
        foreach ($matches as $match) {
            $pairs[] = ['file' => $match[1], 'expect' => rtrim($match[2], "\r\n")];
        }

        return $pairs;
    }

    private function assertAotExecuteFile(string $path, string $expected): void
    {
        $out = tempnam(sys_get_temp_dir(), 'phpc_tce_aot_');
        $this->assertNotFalse($out);
        $env = $this->llvmEnv();
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $path],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim($stderr !== false ? $stderr : ''));

        $procRun = proc_open([$out], $descriptorSpec, $runPipes, $this->repoRoot);
        $this->assertIsResource($procRun);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runExit = proc_close($procRun);
        @unlink($out);
        $this->assertSame(0, $runExit);
        $this->assertSame($expected, $stdout !== false ? $stdout : '');
    }

    private function assertAotExecuteOutput(string $code, string $expected): void
    {
        $src = tempnam(sys_get_temp_dir(), 'phpc_tce_aot_');
        $this->assertNotFalse($src);
        file_put_contents($src, $code);
        $this->assertAotExecuteFile($src, $expected);
        @unlink($src);
    }

    /** @return array<string, string> */
    private function llvmEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        $env['PHP_COMPILER_PROFILE'] = '8.4';
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);

        return $env;
    }
}
