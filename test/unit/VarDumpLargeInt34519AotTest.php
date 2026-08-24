<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_dump/print_r of large ints must not SIGSEGV (#34519 / re-#34507).
 *
 * JitGettype::isClosedStreamHandle must range-guard before GEP into
 * phpc_stream_was_used (StreamGlobalsJit::MAX_HANDLES = 256).
 *
 * @see php-src ext/standard/var.c php_var_dump / zend_print_zval_r
 *
 * @group llvm
 * @group aot
 */
final class VarDumpLargeInt34519AotTest extends TestCase
{
    public function testVmVarDumpLargeIntsAndClosedStream(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34519_vardump_large_int_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34519_vardump_large_int_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString("int(42)\n", $out);
        $this->assertStringContainsString("int(1000000000)\n", $out);
        $this->assertStringContainsString('int('.PHP_INT_MAX.")\n", $out);
        $this->assertStringContainsString('int('.PHP_INT_MIN.")\n", $out);
        $this->assertStringContainsString("1000000000\n", $out);
        $this->assertMatchesRegularExpression('/^resource\(\d+\) of type \(stream\)\n/m', $out);
        $this->assertMatchesRegularExpression('/resource\(\d+\) of type \(Unknown\)/', $out);
    }

    public function testAotVarDumpLargeIntsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34519_vardump_large_int_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34519_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $out = implode("\n", $runOut)."\n";
                $this->assertStringContainsString("int(42)\n", $out, 'run '.($i + 1));
                $this->assertStringContainsString("int(1000000000)\n", $out, 'run '.($i + 1));
                $this->assertStringContainsString('int('.PHP_INT_MAX.")\n", $out, 'run '.($i + 1));
                $this->assertStringContainsString('int('.PHP_INT_MIN.")\n", $out, 'run '.($i + 1));
                $this->assertStringContainsString("1000000000\n", $out, 'run '.($i + 1));
                $this->assertDoesNotMatchRegularExpression('/fatal signal|segfault/i', $out, 'run '.($i + 1));
                $this->assertMatchesRegularExpression(
                    '/^resource\(\d+\) of type \(stream\)\n/m',
                    $out,
                    'run '.($i + 1).': open stream'
                );
                $this->assertMatchesRegularExpression(
                    '/resource\(\d+\) of type \(Unknown\)/',
                    $out,
                    'run '.($i + 1).': closed stream'
                );
            }
        } finally {
            @unlink($bin);
        }
    }
}
