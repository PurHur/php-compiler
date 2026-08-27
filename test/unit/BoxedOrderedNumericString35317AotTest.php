<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: boxed numeric-string < > <= >= int matches Zend (#35317 leftover).
 *
 * @see php-src Zend/zend_operators.c compare_function
 *
 * @group llvm
 * @group aot
 */
final class BoxedOrderedNumericString35317AotTest extends TestCase
{
    public function testAotOrderedMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_35317_leftover_ordered_numeric_string.php';
        $zend = $this->runCmd(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src));
        $bin = sys_get_temp_dir().'/phpc_35317_ord_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $aot = $this->runCmd(escapeshellarg($bin));
            $this->assertSame($zend, $aot);
        } finally {
            @unlink($bin);
        }
    }

    private function runCmd(string $cmd): string
    {
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }
}
