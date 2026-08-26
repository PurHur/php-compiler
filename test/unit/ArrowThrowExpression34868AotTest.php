<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: return/arrow throw expressions must keep Exception live through emitThrow (#34868).
 *
 * Root cause: freeDeadVariables protected RETURN operands but not TYPE_THROW, so uncaught
 * throw in nested functions freed the object before instanceof Throwable.
 *
 * @group llvm
 * @group aot
 */
final class ArrowThrowExpression34868AotTest extends TestCase
{
    public function testStaticMethodReturnedArrowThrowMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34868_static_arrow_throw_segfault.php';
        $this->assertFileExists($src);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $bin = sys_get_temp_dir().'/phpc_34868_'.getmypid().'.bin';
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
        $this->assertSame('s', trim($text));
        $this->assertStringNotContainsString('fatal signal', $text);
        $this->assertStringNotContainsString('Cannot throw objects that do not implement Throwable', $text);
    }

    public function testDirectArrowThrowMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = sys_get_temp_dir().'/phpc_34868_arrow_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
$f = fn () => throw new Exception('x');
try {
    $f();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $bin = sys_get_temp_dir().'/phpc_34868_arrow_'.getmypid().'.bin';
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
        @unlink($src);
        $this->assertSame(0, $compileRc, 'compile failed: '.substr((string) $stderr, 0, 500));

        $out = [];
        $runRc = 0;
        exec(escapeshellarg($bin).' 2>&1', $out, $runRc);
        $text = implode("\n", $out);
        @unlink($bin);

        $this->assertSame(0, $runRc, 'AOT rc='.$runRc.' out='.$text);
        $this->assertStringContainsString('x', $text);
        $this->assertStringNotContainsString('Cannot throw objects that do not implement Throwable', $text);
    }

    public function testFreeDeadVariablesKeepsThrowOperands(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('TYPE_THROW !== $blockOp->type', $source);
        $this->assertStringContainsString('#34868', $source);
    }
}
