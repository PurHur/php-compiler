<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile for wordwrap(..., null) cut_long_words — no LogicException at lower (#29354).
 * Native execute of wordwrap(width) remains a pre-existing AOT SEGV (WordwrapBuiltinTest).
 *
 * @group llvm
 * @group aot
 */
final class WordwrapCutNullAotCompileTest extends TestCase
{
    public function testNullCutForwardProfileAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/wordwrap_cut_null_forward84.php';
        $out = $repo.'/build/test-wordwrap-cut-null-forward84-aot';
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
        $this->assertStringNotContainsString('cut must be a boolean in this compiler build', $combined);
        $this->assertFileExists($out);
    }
}
