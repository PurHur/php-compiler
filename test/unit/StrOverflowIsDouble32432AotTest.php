<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: overflow numeric-string ⊙ int is float (#32432 leftover of #32426).
 *
 * @see php-src Zend/zend_operators.c _is_numeric_string_ex
 *
 * @group llvm
 * @group aot
 */
final class StrOverflowIsDouble32432AotTest extends TestCase
{
    private const EXPECT = "float(9.223372036854776E+18)\n"
        ."float(9.223372036854776E+18)\n"
        ."float(9.223372036854776E+18)\n"
        ."int(13)\n";

    public function testVmOverflowNumericStringIsDouble(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_str_overflow_is_double.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_str_overflow_is_double.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotOverflowNumericStringIsDouble(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_str_overflow_is_double.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32432_sod_'.getmypid().'.bin';
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
