<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #17444: AOT var_dump()/print_r() anonymous class label via VmObjectDebugType SSOT. */
final class VarDumpPrintRAnonymousClassAotCompileTest extends TestCase
{
    public function testAnonymousClassVarDumpPrintRAotCompileSucceeds(): void
    {
        $repo = realpath(__DIR__.'/../..');
        $this->assertNotFalse($repo);
        $source = $repo.'/test/fixtures/aot/compile-only/var_dump_print_r_anonymous_class.php';
        $out = $repo.'/build/test-var-dump-print-r-anon-aot';
        @mkdir($repo.'/build', 0777, true);
        @unlink($out);

        $cmd = [PHP_BINARY, '-d', 'memory_limit=4G', $repo.'/bin/compile.php', '-o', $out, $source];
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

    public function testVmFormattersUseObjectDebugTypeForAnonymousClasses(): void
    {
        $varDump = (string) file_get_contents(__DIR__.'/../../ext/standard/VmVarDump.php');
        $printR = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPrintR.php');
        $this->assertStringContainsString('VmObjectDebugType::fromClassName', $varDump);
        $this->assertStringContainsString('VmObjectDebugType::fromClassName', $printR);
    }
}
