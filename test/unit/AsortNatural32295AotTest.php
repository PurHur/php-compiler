<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: asort(SORT_NATURAL) matches Zend php_natsort (#32295).
 *
 * @see php-src ext/standard/array.c PHP_FUNCTION(asort) / php_natsort
 *
 * @group llvm
 * @group aot
 */
final class AsortNatural32295AotTest extends TestCase
{
    public function testVmAsortNaturalMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_asort_sort_natural_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_asort_sort_natural_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("img1,img2,img10\nImg1,img2,IMG12\n", $out);
    }

    public function testAotAsortNaturalMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_asort_sort_natural_aot.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32295_asort_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("img1,img2,img10\nImg1,img2,IMG12\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($bin);
        }
    }
}
