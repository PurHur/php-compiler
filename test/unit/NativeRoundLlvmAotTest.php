<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * round() places=0 modes via LLVM f64 ops match Zend / RoundJitHelper (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(round) / _php_math_round /
 * php_math_round_mode.h — HALF_UP≡round(3), HALF_DOWN/EVEN/ODD≡trunc+select,
 * CEILING≡ceil, FLOOR≡floor, TOWARD_ZERO≡trunc, AWAY_FROM_ZERO≡ceil(|x|).
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

    public function testRoundPlacesZeroCeilingFloorTowardZeroUseLlvmIntrinsics(): void
    {
        // Mode ints 5/6/7 = PHP_ROUND_CEILING/FLOOR/TOWARD_ZERO (php_math_round_mode.h).
        // Use literals — userland PHP_ROUND_CEILING* are PHP 8.4-only (#22785).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x): void {
            echo round($x, 0, 5), "\n";
            echo round($x, 0, 6), "\n";
            echo round($x, 0, 7), "\n";
        }
        work(1.1);
        work(-1.1);
        PHP;
        $path = sys_get_temp_dir().'/phpc_round_dir_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_round_dir_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @llvm\.ceil\.f64\(/', $body);
            $this->assertMatchesRegularExpression('/call double @llvm\.floor\.f64\(/', $body);
            $this->assertMatchesRegularExpression('/call double @llvm\.trunc\.f64\(/', $body);
            $this->assertStringNotContainsString('round_bridge_entry', $body);
            $this->assertStringNotContainsString('RoundJitHelper', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(6, $runOut);
            // 1.1: ceil=2, floor=1, trunc=1
            $this->assertEqualsWithDelta(2.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[2], 1e-12);
            // -1.1: ceil=-1, floor=-2, trunc=-1
            $this->assertEqualsWithDelta(-1.0, (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(-2.0, (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(-1.0, (float) $runOut[5], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRoundPlacesZeroHalfDownEvenOddAwayUseLlvmWithoutHelperBridge(): void
    {
        // Modes 2/3/4/8 = HALF_DOWN/EVEN/ODD/AWAY_FROM_ZERO (php_math_round_mode.h).
        // Mode 8 is PHP 8.4-only as a named constant — use the int literal.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x): void {
            echo round($x, 0, 2), "\n";
            echo round($x, 0, 3), "\n";
            echo round($x, 0, 4), "\n";
            echo round($x, 0, 8), "\n";
        }
        work(1.5);
        work(2.5);
        work(-1.5);
        work(1.1);
        PHP;
        $path = sys_get_temp_dir().'/phpc_round_half_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_round_half_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @llvm\.trunc\.f64\(/', $body);
            $this->assertMatchesRegularExpression('/call double @llvm\.fabs\.f64\(/', $body);
            $this->assertMatchesRegularExpression('/call double @llvm\.ceil\.f64\(/', $body);
            $this->assertStringNotContainsString('round_bridge_entry', $body);
            $this->assertStringNotContainsString('RoundJitHelper', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);
            $this->assertStringNotContainsString('call double @phpc_round(', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(16, $runOut);
            // 1.5: HD=1 HE=2 HO=1 AF=2
            $this->assertEqualsWithDelta(1.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[3], 1e-12);
            // 2.5: HD=2 HE=2 HO=3 AF=3
            $this->assertEqualsWithDelta(2.0, (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(3.0, (float) $runOut[6], 1e-12);
            $this->assertEqualsWithDelta(3.0, (float) $runOut[7], 1e-12);
            // -1.5: HD=-1 HE=-2 HO=-1 AF=-2
            $this->assertEqualsWithDelta(-1.0, (float) $runOut[8], 1e-12);
            $this->assertEqualsWithDelta(-2.0, (float) $runOut[9], 1e-12);
            $this->assertEqualsWithDelta(-1.0, (float) $runOut[10], 1e-12);
            $this->assertEqualsWithDelta(-2.0, (float) $runOut[11], 1e-12);
            // 1.1: HD=1 HE=1 HO=1 AF=2 (AWAY is PHP 8.4 semantics / RoundJitHelper)
            $this->assertEqualsWithDelta(1.0, (float) $runOut[12], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[13], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[14], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[15], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
