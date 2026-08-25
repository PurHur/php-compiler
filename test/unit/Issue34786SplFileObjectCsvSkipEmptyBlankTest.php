<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT/VM: READ_CSV|SKIP_EMPTY without DROP_NEW_LINE keeps mid-file blank as [null] (#34786).
 *
 * @see php-src ext/spl/spl_directory.c is_line_empty / spl_filesystem_file_read_csv
 *
 * @group llvm
 * @group aot
 */
final class Issue34786SplFileObjectCsvSkipEmptyBlankTest extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $repro = $root.'/test/repro/issue_34786_splfileobject_csv_skip_empty_blank.php';
        $this->assertFileExists($repro);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repro).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $zend = implode("\n", $zendOut)."\n";
        $this->assertStringContainsString('NULL', $zend, 'csv+skip must keep mid-file [null]');
        $this->assertMatchesRegularExpression(
            '/csv\+skip:\narray \(\n  0 => \'a\',\n  1 => \'1\',\n\)\narray \(\n  0 => NULL,\n\)\narray \(\n  0 => \'b\',/s',
            $zend
        );
        $this->assertMatchesRegularExpression(
            '/csv\+skip\+drop:\narray \(\n  0 => \'a\',\n  1 => \'1\',\n\)\narray \(\n  0 => \'b\',/s',
            $zend
        );
        $this->assertDoesNotMatchRegularExpression(
            '/csv\+skip\+drop:.*NULL/s',
            $zend
        );

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1',
            $vmOut,
            $vmRc
        );
        $this->assertSame(0, $vmRc, implode("\n", $vmOut));
        $this->assertSame($zend, implode("\n", $vmOut)."\n");
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34786_splfileobject_csv_skip_empty_blank.php';
        $bin = sys_get_temp_dir().'/phpc_34786_'.getmypid().'.bin';
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
