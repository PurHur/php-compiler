<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {main} for-loop `++$i` / `$i++` with runtime int bound must match Zend (#36385).
 *
 * Peer of {@see AssignOpLoopAccumNativeLongAotTest} / #36018 (function-local only).
 *
 * php-src: Zend/zend_vm_def.h ZEND_PRE_INC; Zend/zend_operators.c compare_function.
 *
 * @group llvm
 */
final class MainScriptLoopIncDecAotTest extends TestCase
{
    public function testMainStrlenBoundPreIncMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/aot_main_loop_strlen_bound.php';
        $this->assertFileExists($src);
        $zend = $this->capture(PHP_BINARY.' '.escapeshellarg($src));
        $this->assertSame("4\n3\n", $zend);
        $aot = $this->compileAndRun($src);
        $this->assertSame($zend, $aot, '{main} for ++/$i++ with strlen/count bound');
    }

    public function testKNucleotideBenchMatchesZend(): void
    {
        $src = dirname(__DIR__, 2).'/benchmarks/v2/k-nucleotide.php';
        $this->assertFileExists($src);
        $zend = $this->capture(PHP_BINARY.' '.escapeshellarg($src));
        $this->assertSame("2000|4000|400|1600\n", $zend);
        $aot = $this->compileAndRun($src);
        $this->assertSame($zend, $aot, 'benchmarks/v2/k-nucleotide.php AOT');
    }

    private function capture(string $cmd): string
    {
        $out = [];
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).("\n");
    }

    private function compileAndRun(string $path): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_main_inc_'.getmypid().'.bin';
        @unlink($bin);
        $llvm = getenv('PHP_COMPILER_LLVM_PATH') ?: '/opt/llvm9';
        $cmd = 'PHP_COMPILER_LLVM_PATH='.escapeshellarg($llvm)
            .' LD_LIBRARY_PATH='.escapeshellarg($llvm.':'.(string) getenv('LD_LIBRARY_PATH'))
            .' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($path);
        $out = [];
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        try {
            return $this->capture(escapeshellarg($bin));
        } finally {
            @unlink($bin);
        }
    }
}
