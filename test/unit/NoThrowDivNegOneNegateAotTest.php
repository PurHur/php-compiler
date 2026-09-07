<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code / -1} lowers to {@code negate} with
 * {@code PHP_INT_MIN} → float promote (omit {@code sdiv}/{@code srem}/exactness
 * CFG) (#36386).
 *
 * php-src: Zend/zend_operators.c div_function.
 * Peer: {@code * -1} (#37222), {@code intdiv($n, -1)} (#37220), typed {@code / 1}
 * (#37214).
 *
 * @group aot-lint
 */
final class NoThrowDivNegOneNegateAotTest extends TestCase
{
    public function testDivNegOneUsesNegateKeepsIntMinPromote(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n / -1;
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_div_neg1_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_div_neg1_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('srem', $body);
            $this->assertStringNotContainsString('longdiv_native_', $body);
            $this->assertStringContainsString('unary_minus_int_min', $body);
            $this->assertMatchesRegularExpression('/\bsub i64 0,|\bsub nsw i64 0,/', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['-7'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDivNegOneIntMinPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n / -1;
        }
        $r = work(PHP_INT_MIN);
        echo gettype($r), ':', $r, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_div_neg1_min_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_div_neg1_min_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut);
            $this->assertStringStartsWith('double:', $runOut[0] ?? '');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeDivisorKeepsSdiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $d): int {
            return $n / $d;
        }
        echo work(10, -1), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_div_neg1_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_div_neg1_rt_'.getmypid().'.bin';
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
            $this->assertSame(['-10'], $runOut);
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
