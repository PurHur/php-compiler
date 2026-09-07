<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long same-operand {@code -}: {@code $n - $n} folds to
 * {@code 0} — omit {@code sub} / overflow intrinsic (#36386).
 *
 * php-src: Zend/zend_operators.c sub_function / ZEND_SIGNED_SUB_OVERFLOW.
 * Peer: same-operand bitwise (#37235), compile-time {@code - 0} (#37217).
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

    private function functionBody(string $ll, string $fn): string
    {
        $fnStart = strpos($ll, 'define i64 @'.$fn.'(i64');
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
