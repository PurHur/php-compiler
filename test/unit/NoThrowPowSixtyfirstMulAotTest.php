<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed integer {@code **} / {@code pow()} with compile-time exponent
 * {@code 61} → {@code (sixtieth*n)} chained smul
 * overflow→float — omit {@code llvm.pow.f64} (#36386).
 *
 * php-src: Zend/zend_operators.c pow_function / zend_pow / mul_function;
 * ext/standard/math.c PHP_FUNCTION(pow).
 * Peer: ** 2–** 49 (#37273/#37277/#37278/#37281/#37283/#37287/#37291/#37326/#37330/#37335/#37336/#37339/#37343/#37344/#37345/#37347/#37351/#37354/#37361/#37364/#37366/#37370/#37375/#37377/#37382/#37384/#37387/#37395/#37397/#37402/#37412/#37415/#37419/#37421/#37424/#37428/#37429/#37438/#37447/#37449/#37453/#37456/#37459/#37462/#37466/#37468/#37474/#37476),
 * ** 50 (#37485),
 * ** 51 (#37494),
 * ** 52 (#37497),
 * ** 53 (#37500),
 * ** 54 (#37503),
 * ** 55 (#37506),
 * ** 56 (#37509),
 * ** 57 (#37510),
 * ** 58 (#37514),
 * ** 59 (#37518),
 * ** 60 (#37521),
 * ** 0 / ** 1 (#37269).
 *
 * @group aot-lint
 */
final class NoThrowPowSixtyfirstMulAotTest extends TestCase
{
    public function testPowSixtyfirstOmitsFpowUsesSmul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ** 61;
        }
        echo work(2), "\n";
        echo work(-2), "\n";
        echo work(0), "\n";
        PHP;
        $this->assertPowSixtyfirst($src, 'work', ['2305843009213693952', '-2305843009213693952', '0']);
    }

    public function testBuiltinPowSixtyfirst(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return pow($n, 61);
        }
        echo work(2), "\n";
        PHP;
        $this->assertPowSixtyfirst($src, 'work', ['2305843009213693952']);
    }

    public function testPowSixtyfirstOverflowPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n ** 61;
        }
        $v = work(PHP_INT_MAX);
        echo gettype($v), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow61_ov_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow61_ov_'.getmypid().'.bin';
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
        echo work(2, 61), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow61_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow61_rt_'.getmypid().'.bin';
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
            $this->assertSame(['2305843009213693952'], $runOut);
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
    private function assertPowSixtyfirst(string $src, string $fn, array $expectedRun): void
    {
        $path = sys_get_temp_dir().'/phpc_pow61_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow61_'.getmypid().'.bin';
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
