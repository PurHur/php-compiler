<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * chunk_split() VM/AOT smoke (issue #971).
 */
final class ChunkSplitBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo chunk_split('1234567890', 3, '-'), "\n";
echo chunk_split('abcde', 2, '|'), "\n";
echo chunk_split('x', 1, '.'), "\n";
echo chunk_split('', 4), "\n";
PHP;

    private const EXPECT = "123-456-789-0-\nab|cd|e|\nx.\n\n";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    /** Issue #3763: invalid length throws ValueError (php-src ext/standard/string.c). */
    public function testVmInvalidLengthThrowsValueError(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
try {
    chunk_split('abc', 0);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP,
            'chunk_split_value_error.php'
        );
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
        }
        $this->assertSame(
            "ValueError\nchunk_split(): Argument #2 (\$length) must be greater than 0\n",
            ob_get_clean()
        );
    }

    public function testExecuteInvalidLengthThrowsValueError(): void
    {
        $runtime = new Runtime();
        $fn = new ext\standard\chunk_split();
        $frame = $fn->getFrame($runtime->vmContext);
        $str = new VM\Variable();
        $str->string('abc');
        $len = new VM\Variable();
        $len->int(0);
        $frame->calledArgs = [$str, $len];
        $frame->returnVar = new VM\Variable();
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('chunk_split(): Argument #2 ($length) must be greater than 0');
        $fn->execute($frame);
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
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_cs_');
        $out = $tmp . '_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo . '/bin/compile.php', '-o', $out, $tmp],
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

        return $this->normalize((string) $result);
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/' . $bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_cs_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
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

        return $this->normalize((string) $result);
    }

    private function normalize(string $output): string
    {
        return str_replace("\r\n", "\n", $output);
    }
}
