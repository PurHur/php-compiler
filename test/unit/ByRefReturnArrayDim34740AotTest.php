<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: by-ref return of $arr[$i] / $arr['k'] aliases the live HT entry (#34740).
 *
 * @see php-src Zend/zend_execute.c ZEND_FETCH_DIM_W / ZEND_RETURN_BY_REF
 *
 * @group llvm
 * @group aot
 */
final class ByRefReturnArrayDim34740AotTest extends TestCase
{
    private const EXPECT = "idx:array (\n  0 => 1,\n  1 => 99,\n  2 => 3,\n)\n"
        ."str:array (\n  'k' => 99,\n)\n"
        ."param:array (\n  0 => 1,\n  1 => 99,\n  2 => 3,\n)\n";

    public function testVmByRefReturnArrayDim(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_34740_array_dim_byref_return.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_34740_array_dim_byref_return.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotByRefReturnArrayDim(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34740_array_dim_byref_return.php';
        $bin = sys_get_temp_dir().'/phpc_aot_byref_arr_dim_'.getmypid().'.bin';
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
