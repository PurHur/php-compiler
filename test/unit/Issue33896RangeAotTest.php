<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT: range() int/char/float must compile+run like Zend (#33896 / re-#26956).
 *
 * Root cause: RangeIntRuntime BasicBlockHelper::append preferred Context::$loweringLlvmFunction
 * (user main) over the bridge insert parent — module verify failed (void returns hashtable /
 * cross-function args). Fix: scopeLoweringToFunction like HashTableUnionRuntime / ArrayFillRuntime.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(range)
 *
 * @group llvm
 * @group aot
 */
final class Issue33896RangeAotTest extends TestCase
{
    public function testAotRangeIntCharFloatMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_range_int.php';
        $bin = sys_get_temp_dir().'/phpc_33896_range_'.getmypid().'_'.mt_rand(1000, 9999).'.bin';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($want, implode("\n", $runOut)."\n");
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }
}
