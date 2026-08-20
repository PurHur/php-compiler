<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: function-static `$s .= '!'` must persist across calls (#32889).
 *
 * php-cfg marks the static CV dead for in-place CONCAT; the ephemeral concat
 * path must still write the module {@see __value__} box (peer #31966 assign,
 * #32814 dim write).
 *
 * @see php-src Zend/zend_execute.c ZEND_ASSIGN_OP / ZEND_CONCAT on static CVs
 *
 * @group llvm
 * @group aot
 */
final class FunctionStaticStringConcat32889AotTest extends TestCase
{
    private const EXPECTED = "hi!hi!!|hi!hi!!\n";

    public function testVmFunctionStaticStringConcat(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_32889_function_static_string_concat.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_32889_function_static_string_concat.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECTED, $out);
    }

    public function testAotFunctionStaticStringConcat(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_32889_function_static_string_concat.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32889_fssc_'.getmypid().'.bin';
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
