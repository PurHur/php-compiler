<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/** str_pad() ValueError parity (#3762 / #29292 — Zend "must not be empty"). */
final class StrPadBuiltinTest extends TestCase
{
    public function testExecuteEmptyPadStringThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\str_pad();
        $frame = $fn->getFrame($runtime->vmContext);
        $input = new VM\Variable();
        $input->string('a');
        $padLength = new VM\Variable();
        $padLength->int(5);
        $padString = new VM\Variable();
        $padString->string('');
        $frame->calledArgs = [$input, $padLength, $padString];
        $frame->returnVar = new VM\Variable();
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_pad(): Argument #3 ($pad_string) must not be empty');
        $fn->execute($frame);
    }

    public function testExecuteEmptyPadStringThrowsWhenReturnDiscarded(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\str_pad();
        $frame = $fn->getFrame($runtime->vmContext);
        $input = new VM\Variable();
        $input->string('a');
        $padLength = new VM\Variable();
        $padLength->int(5);
        $padString = new VM\Variable();
        $padString->string('');
        $frame->calledArgs = [$input, $padLength, $padString];
        $frame->returnVar = null;
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_pad(): Argument #3 ($pad_string) must not be empty');
        $fn->execute($frame);
    }

    public function testVmInvalidPadStringThrowsValueError(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
try {
    str_pad('a', 5, '');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP,
            'str_pad_empty_pad_string.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "ValueError\nstr_pad(): Argument #3 (\$pad_string) must not be empty\n",
            ob_get_clean()
        );
    }

    public function testJitHelperEmptyPadStringThrowsZendMessage(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('str_pad(): Argument #3 ($pad_string) must not be empty');
        \PHPCompiler\ext\standard\StrPadJitHelper::padArgv('a', 5, '', 1);
    }

    /**
     * @group llvm
     */
    public function testAotLintCompilesValidStrPad(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sp_lint_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
echo str_pad('5', 4, '0'), "\n";
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
