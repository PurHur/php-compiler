<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code * -1} / {@code -1 *} lowers to {@code negate} with
 * {@code PHP_INT_MIN} → float promote (omit {@code llvm.smul.with.overflow})
 * (#36386).
 *
 * php-src: Zend/zend_operators.c mul_function / zendi_negate_function.
 * Peer: intdiv($n, -1) (#37220), unary − (#28761).
 *
 * @group aot-lint
 */
final class NoThrowMulNegOneNegateAotTest extends TestCase
{
    public function testMulNegOneUsesNegateKeepsIntMinPromote(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * -1;
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul_neg1_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul_neg1_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertStringNotContainsString('mul i64', $body);
            $this->assertStringContainsString('unary_minus_int_min', $body);
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

    public function testNegOneMulUsesNegate(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return -1 * $n;
        }
        echo work(11), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_neg1_mul_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_neg1_mul_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertStringNotContainsString('mul i64', $body);
            $this->assertMatchesRegularExpression('/\bsub i64 0,|\bsub nsw i64 0,/', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['-11'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMulNegOneIntMinPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n * -1;
        }
        $r = work(PHP_INT_MIN);
        echo gettype($r), ':', $r, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul_neg1_min_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul_neg1_min_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut);
            $this->assertStringStartsWith('double:', $runOut[0] ?? '');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeRhsKeepsSmul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $k): int {
            return $n * $k;
        }
        echo work(3, 4), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.smul.with.overflow.i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['12'], $runOut);
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
