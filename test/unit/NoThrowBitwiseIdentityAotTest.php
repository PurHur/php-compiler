<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code |}/{@code ^} with compile-time {@code 0} and
 * {@code &} with {@code -1} are identity — omit {@code or}/{@code xor}/{@code and}
 * (#36386).
 *
 * php-src: Zend/zend_operators.c bitwise_and/or/xor_function.
 * Peer: shift-count 0 (#37208), +0/-0 overflow skip (#37200).
 *
 * @group aot-lint
 */
final class NoThrowBitwiseIdentityAotTest extends TestCase
{
    public function testOrZeroOmitsOr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n | 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertBitwiseIdentity($src, 'work', 'or', ['7']);
    }

    public function testZeroOrOmitsOr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return 0 | $n;
        }
        echo work(5), "\n";
        PHP;
        $this->assertBitwiseIdentity($src, 'work', 'or', ['5']);
    }

    public function testXorZeroOmitsXor(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ^ 0;
        }
        echo work(11), "\n";
        PHP;
        $this->assertBitwiseIdentity($src, 'work', 'xor', ['11']);
    }

    public function testAndNegOneOmitsAnd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n & -1;
        }
        echo work(42), "\n";
        PHP;
        $this->assertBitwiseIdentity($src, 'work', 'and', ['42']);
    }

    public function testRuntimeRhsKeepsOr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n | $m;
        }
        echo work(1, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_bw_id_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_id_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString(' or ', $body);
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
    private function assertBitwiseIdentity(
        string $src,
        string $fn,
        string $llvmOpcode,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_bw_id_'.$llvmOpcode.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_id_'.$llvmOpcode.'_'.getmypid().'.bin';
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
            // LLVM binary ops: "and i64" / "or i64" / "xor i64" — omit on identity.
            $this->assertStringNotContainsString(' '.$llvmOpcode.' i64', $body);
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
