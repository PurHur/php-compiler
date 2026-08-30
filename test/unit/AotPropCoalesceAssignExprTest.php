<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: `$o->p ??= $x = n` stores on null property and assigns `$x` (#35998).
 *
 * @see php-src Zend/zend_execute.c ZEND_COALESCE / ZEND_ASSIGN
 */
final class AotPropCoalesceAssignExprTest extends TestCase
{
    private const EXPECTED = "int(7)\nint(7)\nint(7)\nint(7)\nint(5)\nNULL\n";

    public function testVmPropCoalesceAssignExpr(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_prop_coalesce_assign_expr.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_prop_coalesce_assign_expr.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    /**
     * @group llvm
     * @group aot
     */
    public function testAotPropCoalesceAssignExpr(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_prop_coalesce_assign_expr.php';
        $bin = sys_get_temp_dir().'/phpc_aot_prop_coalesce_assign_expr_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '.escapeshellarg(PHP_BINARY).' '
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
