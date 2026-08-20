<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: dim .= / **= hydrate FETCH_DIM_W (#32798 leftover of #32789).
 *
 * @see php-src Zend/zend_vm_def.h ZEND_ASSIGN_DIM_OP / ZEND_FETCH_DIM_W
 *
 * @group llvm
 * @group aot
 */
final class DimConcatPowAssignOp32798AotTest extends TestCase
{
    private const EXPECTED = "xy\nab|abb\n8\n4|16\n2\n";

    public function testVmDimConcatPowAssignOp(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32798_dim_concat_pow_assign_op.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32798_dim_concat_pow_assign_op.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotDimConcatPowAssignOp(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32798_dim_concat_pow_assign_op.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32798_dcpa_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
