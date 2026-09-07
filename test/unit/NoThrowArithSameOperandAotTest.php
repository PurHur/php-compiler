<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long same-operand arithmetic (#36386):
 * - {@code $n - $n} → {@code 0} (omit {@code sub} / overflow intrinsic)
 * - {@code $n + $n} → {@code shl 1} with {@code ashr} overflow (peer {@code * 2})
 *
 * php-src: Zend/zend_operators.c sub_function / add_function /
 * ZEND_SIGNED_{SUB,ADD}_OVERFLOW.
 * Peer: same-operand bitwise (#37235), compile-time {@code - 0}/{@code + 0}
 * (#37217), {@code * 2^k} (#37231).
 *
 * @group aot-lint
 */
final class NoThrowArithSameOperandAotTest extends TestCase
{
    public function testSubSelfFoldsToZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n - $n;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandSubFold($src, 'work', ['0']);
    }

    public function testSubSelfIntMinFoldsToZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n - $n;
        }
        echo work(PHP_INT_MIN), "\n";
        PHP;
        $this->assertSameOperandSubFold($src, 'work', ['0']);
    }

    public function testDistinctOperandsKeepSub(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n - $m;
        }
        echo work(10, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arith_same_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_same_rt_'.getmypid().'.bin';
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
            $this->assertTrue(
                false !== strpos($body, 'sub ')
                    || false !== strpos($body, 'ssub.with.overflow')
                    || false !== strpos($body, 'llvm.ssub'),
                "expected sub/overflow in IR:\n".$body
            );
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

    public function testAddSelfUsesShl(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n + $n;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandAddShl($src, 'work', ['14']);
    }

    public function testAddSelfOverflowPromotes(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n + $n;
        }
        $r = work(PHP_INT_MAX);
        echo gettype($r), ':', $r, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arith_same_add_ov_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_same_add_ov_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.sadd.with.overflow.i64', $body);
            $this->assertStringContainsString('shl i64', $body);
            $this->assertStringContainsString('ashr i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut);
            $this->assertStringStartsWith('double:', $runOut[0] ?? '');
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDistinctOperandsKeepAdd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n + $m;
        }
        echo work(10, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arith_same_add_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_same_add_rt_'.getmypid().'.bin';
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
            $this->assertTrue(
                false !== strpos($body, 'add ')
                    || false !== strpos($body, 'sadd.with.overflow')
                    || false !== strpos($body, 'llvm.sadd'),
                "expected add/overflow in IR:\n".$body
            );
            $this->assertStringNotContainsString('native_long_mul_pow2_', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['13'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    /**
     * @param list<string> $expectedRun
     */
    private function assertSameOperandSubFold(
        string $src,
        string $fn,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_arith_same_sub_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_same_sub_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $body = $this->functionBody((string) file_get_contents('/tmp/phpc-last.ll'), $fn);
            $this->assertStringNotContainsString(' sub i64', $body);
            $this->assertStringNotContainsString('ssub.with.overflow', $body);
            $this->assertStringNotContainsString('llvm.ssub', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame($expectedRun, $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    /**
     * @param list<string> $expectedRun
     */
    private function assertSameOperandAddShl(
        string $src,
        string $fn,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_arith_same_add_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_same_add_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $body = $this->functionBody((string) file_get_contents('/tmp/phpc-last.ll'), $fn);
            $this->assertStringNotContainsString('llvm.sadd.with.overflow.i64', $body);
            $this->assertStringNotContainsString(' add i64', $body);
            $this->assertStringContainsString('shl i64', $body);
            $this->assertStringContainsString('ashr i64', $body);
            $this->assertStringContainsString('native_long_mul_pow2_', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame($expectedRun, $runOut);
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
        if (false === $fnStart) {
            $fnStart = strpos($ll, 'define %__value__ @'.$fn.'(i64');
        }
        if (false === $fnStart) {
            $fnStart = strpos($ll, 'define double @'.$fn.'(i64');
        }
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
