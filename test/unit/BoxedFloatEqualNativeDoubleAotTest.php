<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed float == / <=> must not SIGSEGV (#32860).
 *
 * php-src: Zend/zend_operators.c compare_function / spaceship
 *
 * @group llvm
 * @group aot
 */
final class BoxedFloatEqualNativeDoubleAotTest extends TestCase
{
    private const EXPECTED =
        "bool(true)\n"
        ."bool(true)\n"
        ."bool(true)\n"
        ."bool(false)\n"
        ."bool(true)\n"
        ."int(1)\n"
        ."bool(false)\n";

    public function testVmBoxedFloatEqualNativeDouble(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32860_boxed_float_equal.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32860_boxed_float_equal.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotBoxedFloatEqualNativeDouble(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32860_boxed_float_equal.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32860_'.getmypid().'.bin';
        try {
            $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECTED, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
