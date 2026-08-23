<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: uncaught throw of non-Throwable object → Zend Error, no SIGSEGV (#33975).
 *
 * @group llvm
 * @group aot
 */
final class ThrowNonThrowableUncaught33975AotTest extends TestCase
{
    public function testUncaughtNonThrowableMatchesZendMessage(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33975_throw_non_throwable_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir().'/phpc_33975_'.getmypid().'.bin';
        $compileLog = $bin.'.compile';
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
        file_put_contents($compileLog, (string) $stdout.(string) $stderr);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 500));
        $this->assertFileExists($bin);

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        $text = implode("\n", $out);
        @unlink($bin);
        @unlink($compileLog);

        $this->assertStringContainsString(
            'Cannot throw objects that do not implement Throwable',
            $text,
            'AOT output: '.$text
        );
        $this->assertStringNotContainsString('fatal signal', $text);
        $this->assertNotSame(139, $runRc, 'must not SIGSEGV');
    }

    public function testTryCatchHelperGuardsUncaughtNonThrowable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $this->assertStringContainsString('emitUncaughtErrorObject', $source);
        $this->assertStringContainsString('throw_uncaught_non_throwable', $source);
        $this->assertStringContainsString('#33975', $source);
    }
}
