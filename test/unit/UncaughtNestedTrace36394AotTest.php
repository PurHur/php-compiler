<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT uncaught nested throw prints Zend-shaped frames, not only #0 {main} (#36394).
 *
 * php-src: Zend/zend_exceptions.c — zend_exception_error / zend_fetch_debug_backtrace
 *
 * @group llvm
 * @group aot
 */
final class UncaughtNestedTrace36394AotTest extends TestCase
{
    public function testNestedUncaughtTraceNamesCallers(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_uncaught_nested_trace_36394.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir().'/phpc_36394_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = [
            PHP_BINARY,
            $root.'/bin/compile.php',
            '-o',
            $bin,
            $src,
        ];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileRc = proc_close($proc);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 800).substr((string) $stdout, 0, 200));
        $this->assertFileExists($bin);

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        $text = implode("\n", $out);
        @unlink($bin);

        $this->assertStringContainsString('Uncaught Exception: nested', $text, $text);
        $this->assertStringContainsString('inner()', $text, $text);
        $this->assertStringContainsString('outer()', $text, $text);
        $this->assertStringContainsString('{main}', $text, $text);
        $this->assertNotSame(139, $runRc, 'must not SIGSEGV: '.$text);
        $this->assertSame(255, $runRc, 'uncaught Throwable exits 255: '.$text);
    }

    public function testPrinterNoLongerHardcodesOnlyMainFrame(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UncaughtThrowPrinter.php');
        $this->assertStringContainsString('phpc_ex_stack_push', $source);
        $this->assertStringContainsString('#36394', $source);
        $this->assertStringNotContainsString(
            "Stack trace:\\n#0 {main}\\n  thrown in %s on line %d\\n",
            $source
        );
    }
}
