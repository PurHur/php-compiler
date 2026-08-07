<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for associative array unpack (#28673).
 *
 * NestedJIT cannot export runtime string-key HTs from ARRAY_SPREAD; json_encode
 * must CT-fold via CallUnpackCompileTime (peer #27546 array_merge).
 *
 * php-src: Zend/zend_execute.c / zend_vm_def.h — ZEND_FETCH_LIST_R / unpack
 *
 * @group llvm
 * @group aot
 */
final class ArrayUnpackAot28673Test extends TestCase
{
    public function testAotAssocUnpackJsonMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_28673_aot_assoc_array_unpack.php';
        $bin = sys_get_temp_dir().'/phpc_array_unpack_28673_'.getmypid().'.bin';
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

    public function testCallUnpackCompileTimeHandlesArraySpread(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/CallUnpackCompileTime.php'
        );
        $this->assertStringContainsString('TYPE_ARRAY_SPREAD', $src);
        $this->assertStringContainsString('spreadFrom', $src);
        $this->assertStringContainsString('#28673', $src);
    }

    public function testAnalyzerRejectsUnpackAsNativePackedSize(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Analyzer.php'
        );
        $this->assertStringContainsString('unpackFlags', $src);
        $this->assertStringContainsString('#28673', $src);
    }
}
