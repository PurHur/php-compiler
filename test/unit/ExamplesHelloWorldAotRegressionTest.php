<?php

declare(strict_types=1);

require_once __DIR__.'/../LlvmToolchain.php';

use PHPUnit\Framework\TestCase;

/**
 * Issue #13571: examples/000-HelloWorld standalone AOT must build and run (no SIGSEGV).
 *
 * @group llvm
 * @group aot
 */
final class ExamplesHelloWorldAotRegressionTest extends TestCase
{
    public function testContextMinimalUserStandaloneBodiesDefersNestedJit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertStringContainsString('ensureMinimalUserStandaloneBodies', $source);
        $this->assertStringContainsString('ensureUserScriptMainStubs', $source);
        $this->assertStringContainsString('PHP_COMPILER_AOT_USER_SCRIPT', $source);
    }

    public function testStreamIoDefersNestedJitForUserScriptAot(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('PHP_COMPILER_AOT_USER_SCRIPT', $source);
        $this->assertStringContainsString('shouldDeferHeavyStreamIoEmitters', $source);
    }

    public function testHelloWorldExampleStandaloneAotBuildsAndRuns(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available');
        }
        $repoRoot = dirname(__DIR__, 2);
        $example = $repoRoot.'/examples/000-HelloWorld/example.php';
        $outBin = sys_get_temp_dir().'/phpc_hw_'.(string) getmypid();
        @unlink($outBin);
        $cmd = [
            PHP_BINARY,
            $repoRoot.'/bin/compile.php',
            '-o',
            $outBin,
            $example,
        ];
        $env = $_ENV;
        $llvmPath = getenv('PHP_COMPILER_LLVM_PATH');
        if (is_string($llvmPath) && '' !== $llvmPath) {
            $env['PHP_COMPILER_LLVM_PATH'] = $llvmPath;
        }
        $pipes = [];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, $stderr."\n".$stdout);
        $this->assertFileExists($outBin);
        $run = proc_open(
            [$outBin],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $runOut = (string) stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runCode = proc_close($run);
        @unlink($outBin);
        $this->assertSame(0, $runCode, $runOut);
        $this->assertStringContainsString('Hello World', $runOut);
    }
}
