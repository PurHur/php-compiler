<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed integer {@code **} / {@code pow()} with compile-time exponent
 * {@code 0} → {@code 1} and {@code 1} → identity — omit {@code llvm.pow.f64}
 * (#36386).
 *
 * php-src: Zend/zend_operators.c pow_function / zend_pow;
 * ext/standard/math.c PHP_FUNCTION(pow).
 * Peer: * 1 identity (#37217), intdiv($n,$n)→1 (#37266).
 *
 * @group aot-lint
 */
final class NoThrowPowZeroOneIdentityAotTest extends TestCase
{
    public function testPowZeroFoldsToOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ** 0;
        }
        echo work(7), "\n";
        echo work(0), "\n";
        echo work(-3), "\n";
        PHP;
        $this->assertPowFold($src, 'work', ['1', '1', '1'], true);
    }

    public function testPowOneIsIdentity(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ** 1;
        }
        echo work(7), "\n";
        echo work(PHP_INT_MIN), "\n";
        PHP;
        $this->assertPowFold($src, 'work', ['7', (string) \PHP_INT_MIN], false);
    }

    public function testBuiltinPowZeroOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function zero(int $n): int {
            return pow($n, 0);
        }
        function one(int $n): int {
            return pow($n, 1);
        }
        echo zero(5), "\n";
        echo one(5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow_bu_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow_bu_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            foreach (['zero', 'one'] as $fn) {
                $body = $this->functionBody($ll, $fn);
                $this->assertStringNotContainsString('llvm.pow.f64', $body);
                $this->assertStringNotContainsString('@llvm.pow', $body);
                $this->assertStringNotContainsString('phpc_fpow', $body);
            }
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['1', '5'], $runOut);
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
        echo work(2, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow_rt_'.getmypid().'.bin';
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
            $this->assertSame(['8'], $runOut);
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
    private function assertPowFold(
        string $src,
        string $fn,
        array $expectedRun,
        bool $expectConstOne
    ): void {
        $path = sys_get_temp_dir().'/phpc_pow_id_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow_id_'.getmypid().'.bin';
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
            if ($expectConstOne) {
                $this->assertTrue(
                    false !== strpos($body, 'writeLong')
                        || false !== strpos($body, 'store i64 1')
                        || false !== strpos($body, 'i64 1'),
                    "expected const 1 materialization\n".$body
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
