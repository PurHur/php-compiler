<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: array vs runtime/boxed null/bool ordered compare is Zend zend_is_true (#32528 leftover of #32520).
 *
 * @see php-src Zend/zend_operators.c compare_function
 *
 * @group llvm
 * @group aot
 */
final class ArrayRuntimeNullBoolUnlikeCompare32528AotTest extends TestCase
{
    private const EXPECT = "nen\n0\nnef\nnat\n0\nnfn\nnen2\nnz\n";

    public function testVmArrayRuntimeNullBoolUnlikeCompareMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_array_runtime_null_bool_unlike_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_array_runtime_null_bool_unlike_compare.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotArrayRuntimeNullBoolUnlikeCompareMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_array_runtime_null_bool_unlike_compare.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32528_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
