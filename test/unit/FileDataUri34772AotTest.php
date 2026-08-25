<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: file() decodes data:// like Zend (#34772 / peer #34731).
 *
 * @see php-src ext/standard/file.c PHP_FUNCTION(file)
 * @see php-src ext/standard/php_data_wrapper.c
 *
 * @group llvm
 * @group aot
 */
final class FileDataUri34772AotTest extends TestCase
{
    public function testVmFileDataUri(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34772_file_data_uri_aot.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34772_file_data_uri_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('[0] => a', $out);
        $this->assertStringContainsString('[1] => b', $out);
        $this->assertStringContainsString('[0] => x', $out);
        $this->assertStringContainsString('[0] => fs1', $out);
    }

    public function testAotFileDataUriMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34772_file_data_uri_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aot_file_data_34772_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

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
