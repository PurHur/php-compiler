<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long `/` and `%` with a compile-time-safe divisor skip
 * DivisionByZeroError / INT_MIN/-1 modulo PHI (#36386).
 *
 * php-src: Zend/zend_operators.c div_function / mod_function.
 * Peer: intdiv proven-divisor (#37171), log proven-base (#37181).
 *
 * @group aot-lint
 */
final class NoThrowDivModProvenDivisorAotTest extends TestCase
{
    public function testProvenDivisorOmitsZeroGuardOnNativeLongDiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n / 2;
        }
        echo work(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_div_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_div_'.getmypid().'.bin';
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

            $this->assertStringContainsString('sdiv', $body);
            $this->assertStringNotContainsString('numdiv_long_err', $body);
            $this->assertStringNotContainsString('DivisionByZeroError', $body);
            $this->assertStringNotContainsString('phpc_ex_stack_push', $body);

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

    public function testProvenDivisorOmitsZeroAndNegOneOnNativeLongModulo(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n % 2;
        }
        echo work(11), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_mod_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_mod_'.getmypid().'.bin';
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

            $this->assertStringContainsString('srem', $body);
            $this->assertStringNotContainsString('numdiv_long_err', $body);
            $this->assertStringNotContainsString('mod_neg1_zero', $body);
            $this->assertStringNotContainsString('mod_done', $body);
            $this->assertStringNotContainsString('DivisionByZeroError', $body);

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

    public function testRuntimeDivisorKeepsZeroGuardOnNativeLongDiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $d): int {
            return $n / $d;
        }
        echo work(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_div_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_div_rt_'.getmypid().'.bin';
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
