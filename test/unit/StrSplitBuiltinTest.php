<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/** str_split() ValueError parity (issue #3749). */
final class StrSplitBuiltinTest extends TestCase
{
    public function testExecuteNonPositiveLengthThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\str_split();
        $frame = $fn->getFrame($runtime->vmContext);
        $str = new VM\Variable();
        $str->string('');
        $len = new VM\Variable();
        $len->int(0);
        $frame->calledArgs = [$str, $len];
        $frame->returnVar = new VM\Variable();
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_split(): Argument #2 ($length) must be greater than 0');
        $fn->execute($frame);
    }

    public function testExecuteNonPositiveLengthThrowsWhenReturnDiscarded(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\str_split();
        $frame = $fn->getFrame($runtime->vmContext);
        $str = new VM\Variable();
        $str->string('');
        $len = new VM\Variable();
        $len->int(0);
        $frame->calledArgs = [$str, $len];
        $frame->returnVar = null;
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_split(): Argument #2 ($length) must be greater than 0');
        $fn->execute($frame);
    }

    public function testVmInvalidLengthThrowsValueError(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
try {
    str_split('', 0);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP,
            'str_split_nonpositive_length.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "ValueError\nstr_split(): Argument #2 (\$length) must be greater than 0\n",
            ob_get_clean()
        );
    }

    /**
     * @group llvm
     */
    public function testAotLintCompilesValidStrSplit(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ss_lint_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
$p = str_split('abcd', 2);
echo count($p), "\n";
PHP);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo . '/bin/compile.php', '-l', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim((string) $stderr));
        @unlink($tmp);
    }
}
