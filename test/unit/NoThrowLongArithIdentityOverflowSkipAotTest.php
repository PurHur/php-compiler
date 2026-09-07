<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code +}/{@code -}/{@code *} with a compile-time
 * identity/zero operand skips {@code llvm.s{add,sub,mul}.with.overflow}
 * (#36386).
 *
 * php-src: Zend/zend_operators.h ZEND_SIGNED_*_OVERFLOW.
 * Peer: / skip INT_MIN/−1 (#37197), / % proven-divisor (#37187).
 *
 * @group aot-lint
 */
final class NoThrowLongArithIdentityOverflowSkipAotTest extends TestCase
{
    public function testProvenPlusZeroOmitsOverflowIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n + 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertBareArithOmitsOverflow($src, 'work', 'add', ['7']);
    }

    public function testProvenMinusZeroOmitsOverflowIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n - 0;
        }
        echo work(9), "\n";
        PHP;
        $this->assertBareArithOmitsOverflow($src, 'work', 'sub', ['9']);
    }

    public function testProvenMulOneOmitsOverflowIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * 1;
        }
        echo work(11), "\n";
        PHP;
        $this->assertBareArithOmitsOverflow($src, 'work', 'mul', ['11']);
    }

    public function testProvenMulZeroOmitsOverflowIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * 0;
        }
        echo work(11), "\n";
        PHP;
        $this->assertBareArithOmitsOverflow($src, 'work', 'mul', ['0']);
    }

    public function testRuntimeRhsKeepsOverflowIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $k): int {
            return $n + $k;
        }
        echo work(3, 4), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_arith_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_arith_rt_'.getmypid().'.bin';
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
     * @param list<string> $expectedOut
     */
    private function assertBareArithOmitsOverflow(
        string $src,
        string $fn,
        string $bareOp,
        array $expectedOut
    ): void {
        $path = sys_get_temp_dir().'/phpc_nothrow_arith_id_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_arith_id_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $body = $this->functionBody((string) file_get_contents('/tmp/phpc-last.ll'), $fn);

            $this->assertStringNotContainsString('llvm.sadd.with.overflow.i64', $body);
            $this->assertStringNotContainsString('llvm.ssub.with.overflow.i64', $body);
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertStringNotContainsString('native_long_bin_ov', $body);
            // Bare op may be folded away by LLVM ( +0 / *1 ); accept either.
            if ('add' === $bareOp) {
                $this->assertTrue(
                    false !== strpos($body, 'add i64') || 1 === preg_match('/ret i64 %/', $body),
                    "expected bare add or ret of operand\n".$body
                );
            } elseif ('sub' === $bareOp) {
                $this->assertTrue(
                    false !== strpos($body, 'sub i64') || 1 === preg_match('/ret i64 %/', $body),
                    "expected bare sub or ret of operand\n".$body
                );
            } else {
                $this->assertTrue(
                    false !== strpos($body, 'mul i64')
                    || false !== strpos($body, 'ret i64 0')
                    || 1 === preg_match('/ret i64 %/', $body),
                    "expected bare mul / zero / ret of operand\n".$body
                );
            }

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame($expectedOut, $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    private function functionBody(string $ll, string $fn): string
    {
        $this->assertSame(1, preg_match(
            '/define [^\n]*@'.preg_quote($fn, '/').'\([^\)]*\)[^\n]*\{/',
            $ll,
            $m,
            PREG_OFFSET_CAPTURE
        ), 'missing @'.$fn);
        $fnStart = (int) $m[0][1];
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
