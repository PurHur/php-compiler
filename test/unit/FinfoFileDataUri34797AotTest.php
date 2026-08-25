<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: finfo_file(data://) matches Zend RFC2397 decode (#34797 / peer #34731 / #34789).
 *
 * @see php-src ext/fileinfo/fileinfo.c
 * @see php-src ext/standard/php_data_wrapper.c
 *
 * @group llvm
 * @group aot
 */
final class FinfoFileDataUri34797AotTest extends TestCase
{
    private const EXPECT = "plain='text/plain'\nbase64='text/plain'\nfile='text/plain'\nobj='text/plain'\n";

    public function testVmFinfoFileDataUri(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34797_finfo_file_data_uri.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34797_finfo_file_data_uri.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotFinfoFileDataUri(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34797_finfo_file_data_uri.php';
        $bin = sys_get_temp_dir().'/phpc_aot_finfo_data_34797_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
