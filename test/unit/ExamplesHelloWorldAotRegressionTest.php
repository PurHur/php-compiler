<?php

declare(strict_types=1);

require_once __DIR__.'/../LlvmToolchain.php';

use PHPCompiler\LlvmToolchain;
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
        // compileToFile links refresh via ensureStandaloneBodies for every standalone (#35137).
        $this->assertStringContainsString('SuperglobalRefreshRuntime::ensureStandaloneBodies', $source);
        $refresh = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/SuperglobalRefreshRuntime.php');
        $this->assertStringContainsString('ensureUserScriptRefreshPrerequisites', $refresh);
        $this->assertStringContainsString('ensureUserScriptRefreshEmit', $refresh);
        $this->assertStringContainsString('JitSuperglobalRefreshKernel::implement', $refresh);
        $this->assertStringContainsString('#35137', $refresh);
        // StringHtmlspecialchars lazy (#34642); HtmlspecialcharsDecode / HtmlEntities /
        // ErrorHandler / ExceptionHandler lazy (#34612).
        $this->assertStringContainsString('#34642', $source);
        $this->assertStringContainsString('#34612', $source);
        $minimalPos = strpos($source, 'private function ensureMinimalUserStandaloneBodies');
        $this->assertNotFalse($minimalPos);
        $minimalEnd = strpos($source, 'private function ensureBootstrapAotStandaloneBodies', $minimalPos);
        $this->assertNotFalse($minimalEnd);
        $minimalBody = substr($source, $minimalPos, $minimalEnd - $minimalPos);
        $this->assertStringNotContainsString(
            'StringHtmlspecialchars::ensureStandaloneBodies',
            $minimalBody,
            'thin hello-world must not eagerly NestedJIT htmlspecialchars (#34642)'
        );
        $this->assertStringNotContainsString('StringHtmlspecialcharsStandaloneLlvm', $source);
        $this->assertStringNotContainsString('SuperglobalRefreshUserScriptLlvm', $refresh);
        $userScript = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('EnvironMirrorRuntime::ensureLinked', $userScript);
        $runtime = (string) file_get_contents(dirname(__DIR__, 2).'/lib/Runtime.php');
        $this->assertStringContainsString('ensureUserScriptRefreshPrerequisites', $runtime);
        $this->assertStringContainsString('PHP_COMPILER_AOT_USER_SCRIPT', $source);
    }

    public function testStreamIoThinStandaloneUsesKernelEmbedUsesNestedJit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StreamIoRuntime.php');
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('isStandaloneInitPhase', $source);
        // Thin user-script AOT: libc + handle-table kernel (#26929); embed: NestedJIT StreamIoJitHelper (#20943).
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('JitStreamIoKernel::implementForUserScriptLowering', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/StreamIoJit.php');
        $this->assertStringContainsString('StreamIoRuntime::ensureLinked', $jit);
        $this->assertStringContainsString('isThinStandaloneAotMain', $jit);
        $this->assertStringContainsString('JitStreamIoKernel::implementForUserScriptLowering', $jit);
        $this->assertStringNotContainsString('isStandaloneInitPhase', $jit);
        // User-script env SSOT (#20246) — not raw getenv in StreamIoRuntime after #20229 / #20553.
        $this->assertStringNotContainsString('PHP_COMPILER_AOT_USER_SCRIPT', $source);
        $env = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/UserScriptAotEnv.php');
        $this->assertStringContainsString('PHP_COMPILER_AOT_USER_SCRIPT', $env);
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

    public function testSimpleWebExampleStandaloneAotBuilds(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available');
        }
        $repoRoot = dirname(__DIR__, 2);
        $example = $repoRoot.'/examples/001-SimpleWeb/example.php';
        $outBin = sys_get_temp_dir().'/phpc_sw_'.(string) getmypid();
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
        @unlink($outBin);
    }
}
