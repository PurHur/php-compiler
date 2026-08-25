<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: SplFileObject READ_CSV|SKIP_EMPTY EOF must match Zend (no trailing [null]) (#34776).
 *
 * @see php-src ext/spl/spl_directory.c spl_filesystem_file_read_csv
 *
 * @group llvm
 * @group aot
 */
final class Issue34776SplFileObjectReadCsvSkipEmptyAotTest extends TestCase
{
    public function testVmReadCsvSkipEmpty(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34776_splfileobject_read_csv_skip_empty_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34776_splfileobject_read_csv_skip_empty_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/\'a\'.*\'b\'/s', $out);
        $this->assertStringContainsString('false', $out);
    }

    public function testAotReadCsvSkipEmptyMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34776_splfileobject_read_csv_skip_empty_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34776_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        $zendOut = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));

        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(implode("\n", $zendOut), implode("\n", $runOut));
            }
        } finally {
            @unlink($bin);
        }
    }
}
