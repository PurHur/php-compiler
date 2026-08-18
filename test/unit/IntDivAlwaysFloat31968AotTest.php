<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: integer `/` always yields float (zend_div, #31968).
 *
 * @see php-src Zend/zend_operators.c div_function
 *
 * @group llvm
 * @group aot
 */
final class IntDivAlwaysFloat31968AotTest extends TestCase
{
    public function testVmIntDivIsAlwaysFloat(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_31968_int_div_always_float.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_31968_int_div_always_float.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("float(3.5)\nfloat(2.5)\n", $out);
    }

    public function testAotIntDivIsAlwaysFloat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_31968_int_div_always_float.php';
        $bin = sys_get_temp_dir().'/phpc_issue_31968_div_'.getmypid().'.bin';
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
            $this->assertSame("float(3.5)\nfloat(2.5)\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
