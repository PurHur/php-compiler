<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: assigned object vs string/int == / <=> must match Zend (#32540 leftover of #32515).
 *
 * @see php-src Zend/zend_operators.c compare_function / zend_compare
 *
 * @group llvm
 * @group aot
 */
final class RuntimeObjectScalarUnlikeCompare32540AotTest extends TestCase
{
    private const EXPECT = "nident\nneq\n1\n-1\n1\n0\nngt\n";

    public function testVmRuntimeObjectScalarUnlikeCompareMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_runtime_object_scalar_unlike_compare.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_runtime_object_scalar_unlike_compare.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotRuntimeObjectScalarUnlikeCompareMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_runtime_object_scalar_unlike_compare.php';
        $bin = sys_get_temp_dir().'/phpc_issue_32540_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($run = 0; $run < 3; ++$run) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($run + 1).': '.implode("\n", $runOut));
                $this->assertSame(self::EXPECT, implode("\n", $runOut)."\n", 'run '.($run + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
