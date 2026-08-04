<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for array_merge() (#27546).
 *
 * NestedJIT of ArrayMergeJitHelper returned a non-native HashTable; call-site
 * HashTableMergeLlvm must print the merged list / assoc map.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_merge)
 *
 * @group llvm
 * @group aot
 */
final class ArrayMergeAot27546Test extends TestCase
{
    public function testAotArrayMergeMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27546_array_merge_aot.php';
        $bin = sys_get_temp_dir().'/phpc_array_merge_27546_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
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

    public function testArrayMergeRuntimeUsesCallSiteLlvm(): void
    {
        $runtime = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ArrayMergeRuntime.php'
        );
        $this->assertStringContainsString('HashTableMergeLlvm::mergeSingle', $runtime);
        $this->assertStringContainsString('HashTableMergeLlvm::mergeTwo', $runtime);
        $this->assertStringNotContainsString('JitVmHelperLink', $runtime);
    }

    public function testJsonEncodeFoldsArrayMergeLiterals(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitJsonEncodeCompileTime.php'
        );
        $this->assertStringContainsString('tryCompileTimeArrayFromArrayMerge', $src);
        $this->assertStringContainsString("literalArgsForFuncCallResult(\$block, \$slot, 'array_merge')", $src);
    }
}
