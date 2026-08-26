<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: call_user_func('user_fn') / 'Class::method' (#35100).
 *
 * @see php-src ext/standard/basic_functions.c PHP_FUNCTION(call_user_func)
 *
 * @group llvm
 * @group aot
 */
final class CallUserFuncUserFn35100AotTest extends TestCase
{
    private const EXPECT = '4|2|9|9|3|30';

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_call_user_func_user_fn_35100.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_call_user_func_user_fn_35100.php'));
        $this->assertSame(self::EXPECT, (string) ob_get_clean());
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_call_user_func_user_fn_35100.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35100_'.getmypid().'.bin';
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
