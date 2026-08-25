<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: fopen(data://) matches Zend RFC2397 wrapper (#34744).
 *
 * @see php-src ext/standard/php_data_wrapper.c
 *
 * @group llvm
 * @group aot
 */
final class FopenDataUri34744AotTest extends TestCase
{
    private const EXPECT = "plain:string(2) \"hi\"\nbase64:string(2) \"hi\"\nwrite:bool(true)\n";

    public function testVmFopenDataUri(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34744_fopen_data_uri.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34744_fopen_data_uri.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotFopenDataUri(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34744_fopen_data_uri.php';
        $bin = sys_get_temp_dir().'/phpc_aot_fopen_data_34744_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $matched = 0;
            $lastOut = '';
            $lastRc = -1;
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $lastOut = implode("\n", $runOut)."\n";
                $lastRc = $runRc;
                if (0 === $runRc && self::EXPECT === $lastOut) {
                    ++$matched;
                }
            }
            $this->assertSame(
                3,
                $matched,
                "expected 3/3 ok; last rc={$lastRc} out=".var_export($lastOut, true)
            );
        } finally {
            @unlink($bin);
        }
    }
}
