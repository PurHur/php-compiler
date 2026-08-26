<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: call_user_func(['Class','method']) matches Zend (#35090).
 *
 * @see php-src ext/standard/basic_functions.c PHP_FUNCTION(call_user_func)
 * @see peer #32299 direct ['Class','method']() fold
 *
 * @group llvm
 * @group aot
 */
final class CallUserFuncArrayStatic35090AotTest extends TestCase
{
    private const EXPECT = "4\n42";

    public function testVmCallUserFuncArrayStaticMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_call_user_func_array_static.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_call_user_func_array_static.php'));
        $out = rtrim((string) ob_get_clean(), "\n");
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotCallUserFuncArrayStaticMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_call_user_func_array_static.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35090_cufa_'.getmypid().'.bin';
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
            $this->assertSame(self::EXPECT, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
