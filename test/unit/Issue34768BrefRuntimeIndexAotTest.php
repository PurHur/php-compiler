<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: by-ref return of $a[count($a)-1] aliases live HT entry (#34768).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W / ZEND_RETURN_BY_REF
 *
 * @group llvm
 * @group aot
 */
final class Issue34768BrefRuntimeIndexAotTest extends TestCase
{
    public function testVmBrefCountMinusOne(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_34768_bref_runtime_index_aot.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34768_bref_runtime_index_aot.php'));
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('int(8)', $out);
    }

    public function testAotBrefCountMinusOneWritesThrough(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34768_bref_runtime_index_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34768_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 PHP_COMPILER_LLVM_ASSERT=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString('int(8)', $joined);
                $this->assertStringNotContainsString('int(1)', $joined);
            }
        } finally {
            @unlink($bin);
        }
    }
}
