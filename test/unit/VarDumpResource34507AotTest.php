<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: var_dump()/print_r() on stream handles must not print bare ints (#34507).
 *
 * Thin bridges treated TYPE_NATIVE_LONG as plain ints; stream handles need
 * resource()/Resource id formatting (peer ValueEchoHelper::echoNativeLong #4740 / #5149).
 *
 * @see php-src ext/standard/var.c php_var_dump / zend_print_zval_r
 *
 * @group llvm
 * @group aot
 */
final class VarDumpResource34507AotTest extends TestCase
{
    public function testVmVarDumpPrintRResourceShape(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34507_vardump_resource_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34507_vardump_resource_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/^resource\(\d+\) of type \(stream\)\n/m', $out);
        $this->assertMatchesRegularExpression('/^Resource id #\d+$/m', $out);
        $this->assertMatchesRegularExpression('/resource\(\d+\) of type \(Unknown\)/', $out);
    }

    public function testAotVarDumpPrintRResourceMatchZendShape(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34507_vardump_resource_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_34507_vd_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $out = shell_exec(escapeshellarg($bin).' 2>&1');
                $this->assertIsString($out, 'run '.($i + 1));
                $this->assertDoesNotMatchRegularExpression('/^int\(\d+\)\s*$/m', $out, 'run '.($i + 1).': bare int');
                $this->assertMatchesRegularExpression(
                    '/^resource\(\d+\) of type \(stream\)\n/m',
                    $out,
                    'run '.($i + 1).': open'
                );
                $this->assertMatchesRegularExpression(
                    '/^Resource id #\d+$/m',
                    $out,
                    'run '.($i + 1).': print_r'
                );
                $this->assertMatchesRegularExpression(
                    '/resource\(\d+\) of type \(Unknown\)/',
                    $out,
                    'run '.($i + 1).': closed'
                );
            }
        } finally {
            @unlink($bin);
        }
    }
}
