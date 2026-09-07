<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code & 0} → {@code 0}, {@code | -1} → {@code -1},
 * {@code ^ -1} → {@code not} — omit {@code and}/{@code or}/{@code xor} (#36386).
 *
 * php-src: Zend/zend_operators.c bitwise_and/or/xor_function.
 * Peer: bitwise identity |0/^0/&-1 (#37212), * 2^k shl (#37231).
 *
 * @group aot-lint
 */
final class NoThrowBitwiseConstFoldAotTest extends TestCase
{
    public function testAndZeroOmitsAndReturnsZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n & 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertConstFold($src, 'work', 'and', ['0']);
    }

    public function testZeroAndOmitsAndReturnsZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return 0 & $n;
        }
        echo work(5), "\n";
        PHP;
        $this->assertConstFold($src, 'work', 'and', ['0']);
    }

    public function testOrNegOneOmitsOrReturnsNegOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n | -1;
        }
        echo work(42), "\n";
        PHP;
        $this->assertConstFold($src, 'work', 'or', ['-1']);
    }

    public function testNegOneOrOmitsOrReturnsNegOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return -1 | $n;
        }
        echo work(3), "\n";
        PHP;
        $this->assertConstFold($src, 'work', 'or', ['-1']);
    }

    public function testXorNegOneOmitsXorUsesNot(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ^ -1;
        }
        echo work(0), "\n";
        echo work(1), "\n";
        echo work(-2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_bw_xor_not_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_xor_not_'.getmypid().'.bin';
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
            // LLVM lowers {@code not} as {@code xor %x, -1}; reject xor of two SSA values.
            $this->assertDoesNotMatchRegularExpression('/xor i64 %[[0-9A-Za-z_.]+], %[[0-9A-Za-z_.]+]/', $body);
            $this->assertMatchesRegularExpression('/\b(not i64|xor i64 .*, -1)\b/', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['-1', '-2', '1'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeRhsKeepsAnd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n & $m;
        }
        echo work(7, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_bw_cf_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_cf_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString(' and ', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['3'], $runOut);
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
    private function assertConstFold(
        string $src,
        string $fn,
        string $omitOpcode,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_bw_cf_'.$omitOpcode.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_cf_'.$omitOpcode.'_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString(' '.$omitOpcode.' ', $body);
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
            $fnStart = strpos($ll, 'define i64 @'.$fn.'(');
        }
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
