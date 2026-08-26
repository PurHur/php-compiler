<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Fiber::suspend callback must not precompile as normal closure (#35188).
 *
 * @group llvm
 * @group aot
 */
final class Issue35188FiberSuspendPrecompileAotTest extends TestCase
{
    public function testFiberSuspendBasicMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_fiber_suspend_basic.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $expected = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc);

        $bin = sys_get_temp_dir().'/phpc_35188_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 500));

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $out));
        $this->assertSame(implode("\n", $expected), implode("\n", $out));
    }

    public function testPrecompileSkipsFiberSuspendClosures(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('blockContainsFiberSuspend', $src);
        $this->assertStringContainsString('#34868 interaction with #4019', $src);
    }
}
