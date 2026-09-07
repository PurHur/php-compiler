<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code /} with a compile-time divisor ≠ {@code -1} skips
 * the {@code PHP_INT_MIN}/{-1} promote arm (#36386).
 *
 * php-src: Zend/zend_operators.c div_function.
 * Peer: / % proven-divisor (#37187), % skipNeg1, intdiv (#37171).
 *
 * @group aot-lint
 */
final class NoThrowLongDivSkipIntMinNegOneAotTest extends TestCase
{
    public function testProvenNonNegOneDivisorOmitsIntMinPromoteArm(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n / 2;
        }
        echo work(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_div_intmin_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_div_intmin_'.getmypid().'.bin';
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

            $this->assertSame(1, preg_match(
                '/define [^\n]*@work\([^\)]*\)[^\n]*\{/',
                $ll,
                $m,
                PREG_OFFSET_CAPTURE
            ), 'missing @work');
            $fnStart = (int) $m[0][1];
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('sdiv', $body);
            $this->assertStringNotContainsString('numdiv_long_err', $body);
            // INT_MIN constant must not appear — promote is exactness-only.
            $this->assertStringNotContainsString('-9223372036854775808', $body);
            $this->assertStringNotContainsString('and i1', $body);

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

    public function testProvenNegOneDivisorKeepsIntMinPromoteArm(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n / -1;
        }
        echo work(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_div_neg1_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_div_neg1_'.getmypid().'.bin';
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

            $this->assertSame(1, preg_match(
                '/define [^\n]*@work\([^\)]*\)[^\n]*\{/',
                $ll,
                $m,
                PREG_OFFSET_CAPTURE
            ), 'missing @work');
            $fnStart = (int) $m[0][1];
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('-9223372036854775808', $body);
            $this->assertStringContainsString('longdiv_native', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['-10'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeDivisorKeepsIntMinPromoteArm(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $d): int {
            return $n / $d;
        }
        echo work(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_div_rt_intmin_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_div_rt_intmin_'.getmypid().'.bin';
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

            $this->assertSame(1, preg_match(
                '/define [^\n]*@work\([^\)]*\)[^\n]*\{/',
                $ll,
                $m,
                PREG_OFFSET_CAPTURE
            ), 'missing @work');
            $fnStart = (int) $m[0][1];
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('-9223372036854775808', $body);
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
