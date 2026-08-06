<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_walk() by-ref Closure (#26969).
 *
 * NestedJIT of ArrayWalkJitHelper segfaulted under thin AOT after build;
 * ArrayWalkLlvm + NestedClosureInvoke must print Zend/VM/JIT output.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_walk)
 *
 * @group llvm
 * @group aot
 */
final class ArrayWalkAot26969Test extends TestCase
{
    public function testAotArrayWalkByRefClosureMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_26969_array_walk_aot.php';
        $bin = sys_get_temp_dir().'/phpc_aw_26969_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        $expected = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $expected, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $expected));
        $want = implode("\n", $expected)."\n";
        $this->assertSame("2,3\n", $want);
        try {
            // Heap corruption is intermittent on this path historically — multi-run (#23842).
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($want, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            putenv('PHP_COMPILER_HELPER_RUNTIME_O');
            @unlink($bin);
        }
    }

    public function testArrayWalkRuntimeRoutesFlatClosuresViaLlvm(): void
    {
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayWalkRuntime.php'
        );
        $this->assertStringContainsString('ArrayWalkLlvm::walkWithClosure', $runtime);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/ArrayWalkLlvm.php'
        );
        $this->assertStringContainsString('__array_walk__closure_llvm', $llvm);
        $this->assertStringContainsString('NestedClosureInvoke', $llvm);
    }
}
