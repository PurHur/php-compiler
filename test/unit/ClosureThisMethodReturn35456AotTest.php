<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: method-returned closure reading $this must match Zend (not SIGSEGV) (#35456).
 *
 * Root cause: create-time ClosureWithBinding held a method-local $this snapshot alloca;
 * cross-function `$f = $obj->m(); $f()` must reload `__closure_bound_this` from the
 * Closure object (peer #28612 in-method invoke).
 *
 * @group llvm
 * @group aot
 */
final class ClosureThisMethodReturn35456AotTest extends TestCase
{
    public function testMethodReturnedClosureThisMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_closure_this_method_return.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir().'/phpc_35456_'.getmypid().'.bin';
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
        $this->assertFileExists($bin);

        $mismatches = 0;
        for ($i = 0; $i < 5; ++$i) {
            $out = [];
            $runRc = 0;
            exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
            $text = implode("\n", $out);
            if (0 !== $runRc || '3' !== trim($text) || str_contains($text, 'fatal signal')) {
                ++$mismatches;
            }
        }
        @unlink($bin);

        $this->assertSame(0, $mismatches, 'AOT method-returned $this closure mismatched Zend on one or more of 5 runs');
    }

    public function testInMethodArrowThisStillMatchesZend28612(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_28612_arrow_this_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir().'/phpc_28612_'.getmypid().'.bin';
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
        $this->assertFileExists($bin);

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        $text = implode("\n", $out);
        @unlink($bin);

        $this->assertSame(0, $runRc, 'AOT rc='.$runRc.' out='.$text);
        $this->assertSame('5', trim($text));
    }
}
