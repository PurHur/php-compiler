<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT compile for wordwrap(..., '') empty break — no LogicException at lower (#29291).
 * Native execute remains a pre-existing AOT SEGV (WordwrapBuiltinTest / WordwrapCutNull).
 * ValueError wording is asserted on VM/JIT via WordwrapJitHelper (shared AOT helper).
 *
 * @group llvm
 * @group aot
 */
final class WordwrapEmptyBreakMessageAotCompileTest extends TestCase
{
    public function testEmptyBreakAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/wordwrap_empty_break_must_not_be_empty.php';
        $out = $repo.'/build/test-wordwrap-empty-break-message-aot';
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
        $this->assertFileExists($out);
    }
}
