<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_walk_recursive() Closure (#27632).
 *
 * NestedJIT of ArrayWalkJitHelper segfaulted under thin AOT; call-site
 * ArrayWalkLlvm must mutate leaves and TypeError on null.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_walk_recursive)
 *
 * @group llvm
 * @group aot
 */
final class ArrayWalkRecursiveAot27632Test extends TestCase
{
    public function testAotArrayWalkRecursiveMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27632_array_walk_recursive_aot.php';
        $bin = sys_get_temp_dir().'/phpc_awr_27632_'.getmypid().'.bin';
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
        try {
            for ($i = 0; $i < 3; ++$i) {
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

    public function testArrayWalkRuntimeRoutesClosuresViaLlvm(): void
    {
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayWalkRuntime.php'
        );
        $this->assertStringContainsString('ArrayWalkLlvm::walkRecursiveWithClosure', $runtime);
        $this->assertStringContainsString('ArrayWalkLlvm::walkWithClosure', $runtime);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/ArrayWalkLlvm.php'
        );
        $this->assertStringContainsString('NestedClosureInvoke', $llvm);
        $this->assertStringContainsString('__array_walk_recursive__closure_llvm', $llvm);
    }
}
