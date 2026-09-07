<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed integer {@code **} / {@code pow()} with compile-time exponent
 * {@code 2} → {@code $n * $n} with smul overflow→float — omit
 * {@code llvm.pow.f64} (#36386).
 *
 * php-src: Zend/zend_operators.c pow_function / zend_pow / mul_function;
 * ext/standard/math.c PHP_FUNCTION(pow).
 * Peer: ** 0 / ** 1 (#37269), * 2^k shl (#37231).
 *
 * @group aot-lint
 */
final class NoThrowPowSquareMulAotTest extends TestCase
{
    public function testPowSquareOmitsFpowUsesSmul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ** 2;
        }
        echo work(7), "\n";
        echo work(-5), "\n";
        echo work(0), "\n";
        PHP;
        $this->assertPowSquare($src, 'work', ['49', '25', '0']);
    }

    public function testBuiltinPowSquare(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return pow($n, 2);
        }
        echo work(9), "\n";
        PHP;
        $this->assertPowSquare($src, 'work', ['81']);
    }

    public function testPowSquareOverflowPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n ** 2;
        }
        $v = work(PHP_INT_MAX);
        echo gettype($v), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow2_ov_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow2_ov_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.pow.f64', $body);
            $this->assertStringContainsString('smul.with.overflow', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['double'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeExponentKeepsPow(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $e): int {
            return $n ** $e;
        }
        echo work(3, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow2_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow2_rt_'.getmypid().'.bin';
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
                false !== strpos($body, 'llvm.pow.f64')
                    || false !== strpos($body, '@llvm.pow')
                    || false !== strpos($body, 'phpc_fpow')
                    || false !== strpos($body, '__phpc_pow_int'),
                "expected pow intrinsic/helper in IR:\n".$body
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['9'], $runOut);
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
    private function assertPowSquare(string $src, string $fn, array $expectedRun): void
    {
        $path = sys_get_temp_dir().'/phpc_pow2_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow2_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.pow.f64', $body);
            $this->assertStringNotContainsString('@llvm.pow', $body);
            $this->assertStringNotContainsString('phpc_fpow', $body);
            $this->assertStringContainsString('smul.with.overflow', $body);
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
        if (!preg_match(
            '/define[^\n]*@'.preg_quote($fn, '/').'[^\n]*\{(.*?)\n\}/s',
            $ll,
            $m
        )) {
            $this->fail("function @{$fn} not found in IR");
        }

        return $m[1];
    }
}
