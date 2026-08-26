<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach-bound $fn() over string-literal arrays must not abort / mis-dispatch (#35075).
 *
 * php-src: Zend/zend_execute.c — ZEND_INIT_FCALL_BY_NAME
 */
final class ForeachVarFnArrayLiteral35075AotTest extends TestCase
{
    public function testVmForeachVarFnMatchesZendShape(): void
    {
        $src = dirname(__DIR__).'/repro/aot_foreach_var_fn_array_literal.php';
        $code = file_get_contents($src);
        $this->assertNotFalse($code);
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_foreach_var_fn_array_literal.php'));
        $out = ob_get_clean();
        $this->assertSame("2\nabs=2.5\nround=-3.0\n", $out);
    }

    /**
     * @group llvm
     */
    public function testAotForeachVarFnDoesNotAbort(): void
    {
        if ('' === (string) getenv('PHP_COMPILER_LLVM_PATH') && !is_dir(__DIR__.'/../../.llvm')) {
            $this->markTestSkipped('LLVM not configured');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_foreach_var_fn_array_literal.php';
        $bin = sys_get_temp_dir().'/phpc_foreach_vf_35075_'.getmypid().'.bin';
        $cmd = 'php '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin)
            .' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("2\nabs=2.5\nround=-3.0\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
