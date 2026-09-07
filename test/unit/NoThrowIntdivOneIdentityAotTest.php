<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {@code intdiv($n, 1)} is identity (omit sdiv / zero / INT_MIN guards);
 * {@code intdiv($n, -1)} lowers to {@code negate} with INT_MIN guard only when
 * needed (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(intdiv).
 * Peer: typed /1 (#37214), proven-divisor skip (#37171).
 *
 * @group aot-lint
 */
final class NoThrowIntdivOneIdentityAotTest extends TestCase
{
    public function testIntdivOneOmitsSdiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return intdiv($n, 1);
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_intdiv1_id_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_intdiv1_id_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $body = $this->functionBody((string) file_get_contents('/tmp/phpc-last.ll'), 'work');
            $this->assertStringNotContainsString('sdiv', $body);
            $this->assertStringNotContainsString('numdiv_long_err', $body);
            $this->assertStringNotContainsString('intdiv_overflow_err', $body);
            $this->assertStringNotContainsString('sub i64 0', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['7'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testIntdivNegOneUsesNegateKeepsOverflowGuard(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return intdiv($n, -1);
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_intdiv_neg1_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_intdiv_neg1_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $body = $this->functionBody((string) file_get_contents('/tmp/phpc-last.ll'), 'work');
            $this->assertStringNotContainsString('sdiv', $body);
            // Runtime dividend → keep INT_MIN/-1 ArithmeticError; no zero guard.
            $this->assertStringContainsString('intdiv_overflow_err', $body);
            $this->assertStringNotContainsString('numdiv_long_err', $body);
            $this->assertMatchesRegularExpression('/\bsub i64 0,|\bsub nsw i64 0,/', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['-7'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeDivisorKeepsSdiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $d): int {
            return intdiv($n, $d);
        }
        echo work(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_intdiv1_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_intdiv1_rt_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $body = $this->functionBody((string) file_get_contents('/tmp/phpc-last.ll'), 'work');
            $this->assertStringContainsString('sdiv', $body);
            $this->assertStringContainsString('numdiv_long_err', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['5'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    private function functionBody(string $ll, string $fn): string
    {
        $fnStart = strpos($ll, 'define i64 @'.$fn.'(i64');
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
