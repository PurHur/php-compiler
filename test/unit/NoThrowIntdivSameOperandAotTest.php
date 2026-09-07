<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {@code intdiv($n, $n)} → {@code 1} (omit {@code sdiv} / {@code INT_MIN}/{-1}
 * {@code ArithmeticError}; keep {@code DivisionByZeroError} when {@code n == 0})
 * (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(intdiv).
 * Peer: typed {@code $n / $n} (#37245), {@code intdiv($n, 1)} (#37220).
 *
 * @group aot-lint
 */
final class NoThrowIntdivSameOperandAotTest extends TestCase
{
    public function testIntdivSelfFoldsToOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return intdiv($n, $n);
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandIntdivFold($src, 'work', ['1']);
    }

    public function testIntdivSelfIntMinFoldsToOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return intdiv($n, $n);
        }
        echo work(PHP_INT_MIN), "\n";
        PHP;
        $this->assertSameOperandIntdivFold($src, 'work', ['1']);
    }

    public function testIntdivSelfKeepsZeroGuardWithoutSdiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return intdiv($n, $n);
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_intdiv_same_div0_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_intdiv_same_div0_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('sdiv', $body);
            $this->assertStringNotContainsString('intdiv_overflow_err', $body);
            $this->assertStringContainsString('numdiv_long_err', $body);
            $this->assertStringContainsString('icmp eq i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['1'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDistinctOperandsKeepSdiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $a, int $b): int {
            return intdiv($a, $b);
        }
        echo work(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_intdiv_same_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_intdiv_same_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString('sdiv', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['5'], $runOut);
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
    private function assertSameOperandIntdivFold(string $src, string $fn, array $expectedRun): void
    {
        $path = sys_get_temp_dir().'/phpc_intdiv_same_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_intdiv_same_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('sdiv', $body);
            $this->assertStringNotContainsString('intdiv_overflow_err', $body);
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
        if (!preg_match('/define[^\n]*@'.preg_quote($fn, '/').'\(/', $ll, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail('missing define @'.$fn);
        }
        $start = (int) $m[0][1];
        $next = strpos($ll, "\ndefine ", $start + 1);
        return false === $next ? substr($ll, $start) : substr($ll, $start, $next - $start);
    }
}
