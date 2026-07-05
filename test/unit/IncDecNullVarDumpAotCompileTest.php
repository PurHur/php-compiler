<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #16565: AOT compile must not SIGSEGV on ++/-- null + var_dump. */
final class IncDecNullVarDumpAotCompileTest extends TestCase
{
    public function testIncDecNullWithVarDumpAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/incdec_null_vardump.php';
        $out = $repo.'/build/test-incdec-null-vardump-aot';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $cmd = [PHP_BINARY, $repo.'/bin/compile.php', '-o', $out, $source];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim($stdout."\n".$stderr));
        $this->assertFileExists($out);
    }

    public function testVarDumpJitHelperAvoidsDumpValueSymbol(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/VarDumpJitHelper.php');
        $this->assertStringContainsString('formatVariableValue', $helper);
        $this->assertStringNotContainsString('function dumpValue', $helper);
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('emit_dump_value', $jit);
    }
}
