<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * intdiv() with a compile-time-safe divisor skips zero/overflow guards and
 * after-call throw-pending (#36386). php-src ext/standard/math.c PHP_FUNCTION(intdiv).
 *
 * @group aot-lint
 */
final class NoThrowIntdivProvenDivisorAotTest extends TestCase
{
    public function testProvenDivisorOmitsGuardsAndThrowPending(): void
    {
        // Formal dividend (no compileTimeLong) + literal divisor — avoids the
        // stale-loop-CV compileTimeLong fold that would constant-fold intdiv($i,2)
        // to 0 after `$i = 0`.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return intdiv($n, 2);
        }
        echo work(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_intdiv_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_intdiv_'.getmypid().'.bin';
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
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $fnStart = strpos($ll, 'define i64 @work(i64)');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            // Proven divisor 2 → bare sdiv, no zero / INT_MIN/-1 error blocks.
            $this->assertStringContainsString('sdiv', $body);
            $this->assertStringNotContainsString('numdiv_long_err', $body);
            $this->assertStringNotContainsString('intdiv_overflow_err', $body);
            $this->assertStringNotContainsString('DivisionByZeroError', $body);
            $this->assertStringNotContainsString('ArithmeticError', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

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

    public function testRuntimeDivisorKeepsZeroGuard(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $d): int {
            return intdiv($n, $d);
        }
        echo work(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_intdiv_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_intdiv_rt_'.getmypid().'.bin';
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
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $fnStart = strpos($ll, 'define i64 @work(i64');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('numdiv_long_err', $body);
            $this->assertStringContainsString('intdiv_overflow_err', $body);

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
}
