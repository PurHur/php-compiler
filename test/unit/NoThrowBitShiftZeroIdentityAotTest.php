<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code <<}/{@code >>} with compile-time count {@code 0}
 * is identity — omit {@code shl}/{@code ashr} and the negative-count guard
 * (#36386).
 *
 * php-src: Zend/zend_operators.c shift_left_function / shift_right_function.
 * Peer: +0/-0 overflow skip (#37200), proven ≥0 count (#37191).
 *
 * @group aot-lint
 */
final class NoThrowBitShiftZeroIdentityAotTest extends TestCase
{
    public function testProvenZeroCountOmitsShl(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n << 0;
        }
        echo work(7), "\n";
        PHP;
        $this->assertShiftZeroIdentity($src, 'work', 'shl', ['7']);
    }

    public function testProvenZeroCountOmitsAshr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n >> 0;
        }
        echo work(11), "\n";
        PHP;
        $this->assertShiftZeroIdentity($src, 'work', 'ashr', ['11']);
    }

    public function testRuntimeCountKeepsShl(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $c): int {
            return $n << $c;
        }
        echo work(3, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_shift0_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_shift0_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString('shl', $body);
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

    /**
     * @param list<string> $expectedRun
     */
    private function assertShiftZeroIdentity(
        string $src,
        string $fn,
        string $shiftOpcode,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_shift0_'.$shiftOpcode.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_shift0_'.$shiftOpcode.'_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString($shiftOpcode, $body);
            $this->assertStringNotContainsString('bitshift_count_err', $body);
            $this->assertStringNotContainsString('ArithmeticError', $body);
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
        // One-arg: define i64 @work(i64); two-arg: define i64 @work(i64 %…, i64 %…)
        $fnStart = strpos($ll, 'define i64 @'.$fn.'(i64');
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
