<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long same-operand comparisons (#36386):
 * - {@code $n === $n} / {@code $n == $n} / {@code $n <= $n} / {@code $n >= $n}
 *   → {@code true} (omit {@code icmp} / resource-identity CFG)
 * - {@code $n !== $n} / {@code $n != $n} / {@code $n < $n} / {@code $n > $n}
 *   → {@code false}
 * - {@code $n <=> $n} → {@code 0}
 *
 * php-src: Zend/zend_operators.c compare_function / is_identical_function /
 * is_equal_function / zend_compare_longs.
 * Peer: same-operand arith/bitwise (#37245 / #37235).
 *
 * @group aot-lint
 */
final class NoThrowCompareSameOperandAotTest extends TestCase
{
    public function testIdenticalSelfFoldsTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n === $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', true, ['1']);
    }

    public function testEqualSelfFoldsTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n == $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', true, ['1']);
    }

    public function testNotIdenticalSelfFoldsFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n !== $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', false, ['0']);
    }

    public function testNotEqualSelfFoldsFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n != $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', false, ['0']);
    }

    public function testSmallerSelfFoldsFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n < $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', false, ['0']);
    }

    public function testGreaterSelfFoldsFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n > $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', false, ['0']);
    }

    public function testSmallerOrEqualSelfFoldsTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n <= $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', true, ['1']);
    }

    public function testGreaterOrEqualSelfFoldsTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n >= $n ? 1 : 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandBoolFold($src, 'work', true, ['1']);
    }

    public function testSpaceshipSelfFoldsZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n <=> $n;
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cmp_same_ship_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cmp_same_ship_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('icmp ', $body);
            $this->assertStringContainsString('i64 0', $body);
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

    public function testDistinctOperandsKeepIcmp(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n === $m ? 1 : 0;
        }
        echo work(7, 7), "\n";
        echo work(7, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cmp_same_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cmp_same_rt_'.getmypid().'.bin';
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
                false !== strpos($body, 'icmp ')
                    || false !== strpos($body, 'nativeLongEqual')
                    || false !== strpos($body, 'resource'),
                "expected compare IR in:\n".$body
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['1', '0'], $runOut);
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
    private function assertSameOperandBoolFold(
        string $src,
        string $fn,
        bool $expectTrueConst,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_cmp_same_'.($expectTrueConst ? 't' : 'f').'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cmp_same_'.($expectTrueConst ? 't' : 'f').'_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('icmp ', $body);
            if ($expectTrueConst) {
                $this->assertTrue(
                    false !== strpos($body, 'i1 true')
                        || false !== strpos($body, 'i1 1'),
                    "expected const true in IR:\n".$body
                );
            } else {
                $this->assertTrue(
                    false !== strpos($body, 'i1 false')
                        || false !== strpos($body, 'i1 0'),
                    "expected const false in IR:\n".$body
                );
            }
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
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
