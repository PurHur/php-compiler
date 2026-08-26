<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: second call_user_func(...[1,2]) inside ?: after a prior CUF ternary (#35105).
 *
 * @see php-src ext/standard/basic_functions.c PHP_FUNCTION(call_user_func)
 * @see php-src Zend/zend_vm_def.h ZEND_SEND_UNPACK
 *
 * @group llvm
 * @group aot
 */
final class CallUserFuncTernarySplat35105AotTest extends TestCase
{
    private const EXPECT = '3|3';

    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/aot_call_user_func_ternary_splat.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_call_user_func_ternary_splat.php'));
        $this->assertSame(self::EXPECT, (string) ob_get_clean());
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_call_user_func_ternary_splat.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35105_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECT, implode("\n", $runOut), 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testAotAloneSplatTernaryStillMatches(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_call_user_func_ternary_splat_alone.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35105_alone_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame('3', implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }
}
