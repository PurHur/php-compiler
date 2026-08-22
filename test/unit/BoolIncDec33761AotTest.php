<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: untyped bool ++/-- stays bool (#33761).
 *
 * php-src: Zend/zend_operators.c increment_function / decrement_function IS_TRUE/IS_FALSE
 *
 * @group llvm
 * @group aot
 */
final class BoolIncDec33761AotTest extends TestCase
{
    private const EXPECTED =
        "bool(true)\n".
        "bool(false)\n".
        "bool(true)\n".
        "bool(false)\n".
        "bool(true)\n".
        "bool(true)\n";

    public function testAotBoolIncDecNoOp(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33761_bool_incdec_aot.php';
        $bin = sys_get_temp_dir().'/phpc_bool_incdec_33761_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
