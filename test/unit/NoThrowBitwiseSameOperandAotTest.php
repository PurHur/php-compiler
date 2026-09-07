<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long same-operand {@code &}|{@code ^}: {@code $n & $n} /
 * {@code $n | $n} are identity; {@code $n ^ $n} folds to {@code 0} — omit
 * {@code and}/{@code or}/{@code xor} (#36386).
 *
 * php-src: Zend/zend_operators.c bitwise_and/or/xor_function.
 * Peer: compile-time {@code |0}/{@code ^0}/{@code &-1} (#37212).
 *
 * @group aot-lint
 */
final class NoThrowBitwiseSameOperandAotTest extends TestCase
{
    public function testAndSelfOmitsAnd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n & $n;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandFold($src, 'work', 'and', ['7']);
    }

    public function testOrSelfOmitsOr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n | $n;
        }
        echo work(5), "\n";
        PHP;
        $this->assertSameOperandFold($src, 'work', 'or', ['5']);
    }

    public function testXorSelfFoldsToZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ^ $n;
        }
        echo work(11), "\n";
        PHP;
        $this->assertSameOperandFold($src, 'work', 'xor', ['0']);
    }

    public function testDistinctOperandsKeepAnd(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n & $m;
        }
        echo work(7, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_bw_same_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_same_rt_'.getmypid().'.bin';
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
    private function assertSameOperandFold(
        string $src,
        string $fn,
        string $llvmOpcode,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_bw_same_'.$llvmOpcode.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_bw_same_'.$llvmOpcode.'_'.getmypid().'.bin';
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
