<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed integer {@code **} / {@code pow()} with compile-time exponent
 * {@code 65} → {@code (sixtyfourth*n)} chained smul
 * overflow→float — omit {@code llvm.pow.f64} (#36386).
 *
 * php-src: Zend/zend_operators.c pow_function / zend_pow / mul_function;
 * ext/standard/math.c PHP_FUNCTION(pow).
 * Peer: ** 2–** 64 (#37273…/#37534), ** 0 / ** 1 (#37269).
 * Note: {@code (±2)**65} and larger magnitudes promote to float (signed i64).
 *
 * @group aot-lint
 */
final class NoThrowPowSixtyfifthMulAotTest extends TestCase
{
    public function testPowSixtyfifthOmitsFpowUsesSmul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ** 65;
        }
        echo work(0), "\n";
        echo work(1), "\n";
        echo work(-1), "\n";
        PHP;
        $this->assertPowSixtyfifth($src, 'work', ['0', '1', '-1']);
    }

    public function testBuiltinPowSixtyfifth(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return pow($n, 65);
        }
        echo work(-1), "\n";
        PHP;
        $this->assertPowSixtyfifth($src, 'work', ['-1']);
    }

    public function testPowSixtyfifthOverflowPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n ** 65;
        }
        echo gettype(work(2)), "\n";
        echo gettype(work(-2)), "\n";
        echo gettype(work(PHP_INT_MAX)), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow65_ov_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow65_ov_'.getmypid().'.bin';
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
            $this->assertSame(['double', 'double', 'double'], $runOut);
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
        echo work(-1, 65), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow65_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow65_rt_'.getmypid().'.bin';
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
            $this->assertSame(['-1'], $runOut);
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
    private function assertPowSixtyfifth(string $src, string $fn, array $expectedRun): void
    {
        $path = sys_get_temp_dir().'/phpc_pow65_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow65_'.getmypid().'.bin';
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
