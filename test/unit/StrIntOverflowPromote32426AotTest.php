<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: numeric-string integer overflow promotes to float (#32426 leftover of #31964).
 *
 * @see php-src Zend/zend_operators.h ZEND_SIGNED_ADD_OVERFLOW / ZEND_LONG_MUL_OVERFLOW
 *
 * @group llvm
 * @group aot
 */
final class StrIntOverflowPromote32426AotTest extends TestCase
{
    private const EXPECT = "s+i float(9.223372036854776E+18)\n"
        ."i+s float(9.223372036854776E+18)\n"
        ."s+s float(9.223372036854776E+18)\n"
        ."s*i float(1.8446744073709552E+19)\n"
        ."i*s float(1.8446744073709552E+19)\n"
        ."rt+ float(9.223372036854776E+18)\n"
        ."ok+ int(13)\n"
        ."ok- int(7)\n"
        ."ok* int(42)\n";

    public function testVmStrIntOverflowPromotesToFloat(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_str_int_overflow.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_str_int_overflow.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotStrIntOverflowPromotesToFloat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_str_int_overflow.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32426_str_'.getmypid().'.bin';
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
