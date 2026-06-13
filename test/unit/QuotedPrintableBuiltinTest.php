<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * quoted_printable_encode/decode VM/AOT smoke (issue #4828).
 */
final class QuotedPrintableBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$raw = "foo bar\r\n";
$enc = quoted_printable_encode($raw);
echo (quoted_printable_decode($enc) === $raw) ? "1" : "0";
PHP;

    /** Issue #4828: non-string $string throws TypeError (php-src ext/standard/quot_print.c). */
    public function testVmNonStringThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
try {
    quoted_printable_encode([]);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    quoted_printable_decode(new stdClass());
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP,
            'quoted_printable_type_error.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "TypeError\nquoted_printable_encode(): Argument #1 (\$string) must be of type string, array given\n"
            . "TypeError\nquoted_printable_decode(): Argument #1 (\$string) must be of type string, stdClass given\n",
            ob_get_clean()
        );
    }

    public function testExecuteNonStringThrowsTypeError(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\quoted_printable_encode();
        $frame = $fn->getFrame($runtime->vmContext);
        $bad = new VM\Variable();
        $bad->array(new VM\HashTable());
        $frame->calledArgs = [$bad];
        $frame->returnVar = new VM\Variable();
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage(
            'quoted_printable_encode(): Argument #1 ($string) must be of type string, array given'
        );
        $fn->execute($frame);
    }

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame('1', $this->runBin('bin/vm.php'));
    }

    /**
     * Issue #4596: AOT compile-only verify for quoted_printable_* TypeError lowering.
     *
     * @group llvm
     */
    public function testAotCompileOnlyTypeErrorLowering(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $target = dirname(__DIR__, 2).'/test/fixtures/aot/compile-only/quoted_printable_type.php';
        $this->assertFileExists($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile((string) file_get_contents($target), 'quoted_printable_type_jit_compile.php');
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeBinaryMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame('1', $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_qp_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run));
        @unlink($tmp);
        @unlink($out);

        return str_replace("\r\n", "\n", (string) $result);
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_qp_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return str_replace("\r\n", "\n", (string) $result);
    }
}
