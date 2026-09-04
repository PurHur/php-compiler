<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * round() places=0 HALF_UP via llvm.round.f64 matches Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(round) / _php_math_round
 * (half away from zero ≡ C round(3) / llvm.round.f64).
 * Compile-time scalar round() folds on the host — IR checks use float formals.
 *
 * @group aot-lint
 */
final class NativeRoundLlvmAotTest extends TestCase
{
    public function testRoundFloatFormalLoopUsesLlvmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += round($x);
            }
            echo $s, "\n";
        }
        work(1.5, 10);
        work(-0.5, 4);
        work(2.5, 2);
        PHP;
        $path = sys_get_temp_dir().'/phpc_round_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_round_formal_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $sig = null;
            if (preg_match('/define void @work\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define void @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertMatchesRegularExpression('/call double @llvm\.round\.f64\(/', $body);
            $this->assertStringNotContainsString('round_bridge_entry', $body);
            $this->assertStringNotContainsString('RoundJitHelper', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(3, $runOut);
            $this->assertEqualsWithDelta(20.0, (float) $runOut[0], 1e-9);
            $this->assertEqualsWithDelta(-4.0, (float) $runOut[1], 1e-9);
            $this->assertEqualsWithDelta(6.0, (float) $runOut[2], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRoundWithExplicitPlacesZeroHalfUpUsesLlvm(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x): void {
            echo round($x, 0), "\n";
            echo round($x, 0, PHP_ROUND_HALF_UP), "\n";
        }
        work(2.5);
        work(-2.5);
        PHP;
        $path = sys_get_temp_dir().'/phpc_round_p0_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_round_p0_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $sig = null;
            if (preg_match('/define void @work\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define void @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertMatchesRegularExpression('/call double @llvm\.round\.f64\(/', $body);
            $this->assertStringNotContainsString('round_bridge_entry', $body);
            $this->assertStringNotContainsString('RoundJitHelper', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(4, $runOut);
            $this->assertEqualsWithDelta(3.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(3.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(-3.0, (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(-3.0, (float) $runOut[3], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
