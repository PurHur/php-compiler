<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #13240 / #16565: standalone AOT print_r(, true) via PrintRJitHelper PHP. */
final class IncDecNullPrintRAotCompileTest extends TestCase
{
    public function testPrintRReturnTrueAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/print_r_return_simple.php';
        $out = $repo.'/build/test-print-r-return-simple-aot';
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
}
