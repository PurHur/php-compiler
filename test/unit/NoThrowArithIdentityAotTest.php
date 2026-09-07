<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code + 0}/{@code - 0}/{@code * 1} are identity (omit
 * add/sub/mul); typed {@code * 0} folds to {@code 0} (#36386).
 *
 * php-src: Zend/zend_operators.c add/sub/mul_function.
 * Peer: /1 (#37214), |0 (#37212), <<0 (#37208), overflow-skip (#37200).
 *
 * @group aot-lint
 */
final class NoThrowArithIdentityAotTest extends TestCase
{
    public function testPlusZeroOmitsAdd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n + 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertArithIdentity($src, 'work', ['7']);
    }

    public function testZeroPlusOmitsAdd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return 0 + $n;
        }
        echo work(5), "\n";
        PHP;
        $this->assertArithIdentity($src, 'work', ['5']);
    }

    public function testMinusZeroOmitsSub(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n - 0;
        }
        echo work(9), "\n";
        PHP;
        $this->assertArithIdentity($src, 'work', ['9']);
    }

    public function testMulOneOmitsMul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * 1;
        }
        echo work(11), "\n";
        PHP;
        $this->assertArithIdentity($src, 'work', ['11']);
    }

    public function testOneMulOmitsMul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return 1 * $n;
        }
        echo work(13), "\n";
        PHP;
        $this->assertArithIdentity($src, 'work', ['13']);
    }

    public function testMulZeroFoldsToZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * 0;
        }
        echo work(42), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arith_mul0_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_mul0_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('mul i64', $body);
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertTrue(
                false !== strpos($body, 'store i64 0')
                || false !== strpos($body, 'ret i64 0'),
                "expected constant 0\n".$body
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['0'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeRhsKeepsAdd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $k): int {
            return $n + $k;
        }
        echo work(3, 4), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arith_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.sadd.with.overflow.i64', $body);
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

    /**
     * @param list<string> $expectedRun
     */
    private function assertArithIdentity(string $src, string $fn, array $expectedRun): void
    {
        $path = sys_get_temp_dir().'/phpc_arith_id_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arith_id_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('add i64', $body);
            $this->assertStringNotContainsString('sub i64', $body);
            $this->assertStringNotContainsString('mul i64', $body);
            $this->assertStringNotContainsString('llvm.sadd.with.overflow.i64', $body);
            $this->assertStringNotContainsString('llvm.ssub.with.overflow.i64', $body);
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertStringNotContainsString('native_long_bin_ov', $body);
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
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
