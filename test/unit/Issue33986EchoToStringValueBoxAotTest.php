<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: echo/print of assigned object must call __toString (#33986).
 *
 * @group llvm
 * @group aot
 */
final class Issue33986EchoToStringValueBoxAotTest extends TestCase
{
    public function testEchoAssignedObjectMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33986_echo_tostring_valuebox_aot.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $zendProc = proc_open([PHP_BINARY, $src], $desc, $zendPipes, $root, $_ENV);
        $this->assertIsResource($zendProc);
        fclose($zendPipes[0]);
        $zendOut = (string) stream_get_contents($zendPipes[1]);
        fclose($zendPipes[1]);
        fclose($zendPipes[2]);
        $this->assertSame(0, proc_close($zendProc));
        $this->assertSame("S|S|S|S\n", $zendOut);

        $bin = sys_get_temp_dir().'/phpc_33986_'.getmypid().'.bin';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $root);
        $proc = proc_open(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $bin, $src],
            $desc,
            $pipes,
            $root,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), 'compile failed: '.substr($stderr, 0, 500));
        $this->assertFileExists($bin);

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        @unlink($bin);
        $text = implode("\n", $out);
        if (!str_ends_with($text, "\n")) {
            $text .= "\n";
        }
        $this->assertSame(0, $runRc, 'aot rc='.$runRc.' out='.$text);
        $this->assertSame($zendOut, $text);
        $this->assertStringNotContainsString('Object', $text);
        $this->assertStringNotContainsString('fatal signal', $text);
    }

    public function testValueEchoRuntimeThreadsClassHint(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ValueEchoRuntime.php');
        $this->assertStringContainsString('#33986', $source);
        $this->assertStringContainsString('echoObjectVariable($context, $objVar, $classHint)', $source);
    }
}
