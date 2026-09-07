<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Loop-carried typed {@code $s += $i} must not fold as {@code 0 + $i} (#36385).
 *
 * Root cause: {@see \PHPCompiler\JIT\DiscardedPureCallElision::compileTimeLongScalar}
 * treated alloca {@see Variable::$compileTimeLong} from {@code $s = 0} as a live
 * constant inside the loop (peer #32605 / #36386 +0 identity fold).
 *
 * php-src: Zend/zend_operators.c add_function — runtime left operand.
 *
 * @group llvm
 */
final class AssignOpLoopAccumNativeLongAotTest extends TestCase
{
    public function testLoopAccumMatchesZend(): void
    {
        $src = dirname(__DIR__).'/repro/assign_op_accum_int.php';
        $this->assertFileExists($src);
        $zend = $this->capture(PHP_BINARY.' '.escapeshellarg($src));
        $this->assertSame("45\n", $zend);
        $aot = $this->compileAndRun($src);
        $this->assertSame($zend, $aot, 'AOT loop $s += $i must match Zend');
    }

    public function testDualPlusEqualInLoopMatchesZend(): void
    {
        $src = <<<'PHP'
<?php
function w(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; ++$i) {
        $s += 1;
        $s += 1;
    }
    return $s;
}
echo w(10), "\n";
PHP;
        $path = sys_get_temp_dir().'/phpc_dual_plus_'.getmypid().'.php';
        file_put_contents($path, $src);
        try {
            $zend = $this->capture(PHP_BINARY.' '.escapeshellarg($path));
            $this->assertSame("20\n", $zend);
            $aot = $this->compileAndRun($path);
            $this->assertSame($zend, $aot, 'two += in one loop body must both update the alloca');
        } finally {
            @unlink($path);
        }
    }

    public function testCallHeavySubsetMatchesZend(): void
    {
        $src = <<<'PHP'
<?php
function id(int $x): int { return $x; }
function work(int $n): int {
    $s = 0;
    for ($i = 0; $i < $n; ++$i) {
        $s += id($i);
        $s += strlen('x');
    }
    return $s;
}
echo work(10), "\n";
PHP;
        $path = sys_get_temp_dir().'/phpc_call_heavy_subset_'.getmypid().'.php';
        file_put_contents($path, $src);
        try {
            $zend = $this->capture(PHP_BINARY.' '.escapeshellarg($path));
            $this->assertSame("55\n", $zend);
            $aot = $this->compileAndRun($path);
            $this->assertSame($zend, $aot);
        } finally {
            @unlink($path);
        }
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
        $bin = sys_get_temp_dir().'/phpc_accum_'.getmypid().'.bin';
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
