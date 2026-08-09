<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile+run for htmlspecialchars(..., null) double_encode (#29445).
 *
 * @group llvm
 * @group aot
 */
final class HtmlspecialcharsDoubleEncodeNullAotCompileTest extends TestCase
{
    public function testNullDoubleEncodeForwardProfileAotCompileAndRun(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/htmlspecialchars_double_encode_null_forward84.php';
        $out = $repo.'/build/test-htmlspecialchars-double-encode-null-forward84-aot';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $env = array_merge(getenv(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $cmd = [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $source];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $combined = trim($stdout."\n".$stderr);
        $this->assertSame(0, $exit, $combined);
        $this->assertStringNotContainsString('LogicException', $combined);
        $this->assertStringNotContainsString(
            'double_encode must be a boolean in this compiler build',
            $combined
        );
        $this->assertFileExists($out);

        $run = proc_open(
            [$out],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo
        );
        $this->assertIsResource($run);
        $runOut = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runExit = proc_close($run);
        $this->assertSame(0, $runExit, trim($runOut."\n".$runErr));
        $this->assertSame("ok\nok\n", $runOut);
    }
}
