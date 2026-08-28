<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: callable parameter invoke accepts static array callables (#13686 residual).
 *
 * @see php-src Zend/zend_callables.c zend_is_callable / call_user_func*
 * @see peer #32299 direct ['Class','method']() fold
 *
 * @group llvm
 * @group aot
 */
final class CallableStaticArrayParam13686AotTest extends TestCase
{
    private const EXPECT = 'ok';

    public function testVmCallableStaticArrayParamMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_callable_static_array_param.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_callable_static_array_param.php'));
        $out = rtrim((string) ob_get_clean(), "\n");
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotCallableStaticArrayParamMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_callable_static_array_param.php';
        $bin = sys_get_temp_dir().'/phpc_issue_13686_csap_'.getmypid().'.bin';
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
