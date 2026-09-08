<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed integer {@code **} / {@code pow()} with compile-time exponent
 * {@code 37} → {@code (thirtysixth*n)} chained smul
 * overflow→float — omit {@code llvm.pow.f64} (#36386).
 *
 * php-src: Zend/zend_operators.c pow_function / zend_pow / mul_function;
 * ext/standard/math.c PHP_FUNCTION(pow).
 * Peer: ** 2–** 36 (#37273/#37277/#37278/#37281/#37283/#37287/#37291/#37326/#37330/#37335/#37336/#37339/#37343/#37344/#37345/#37347/#37351/#37354/#37361/#37364/#37366/#37370/#37375/#37377/#37382/#37384/#37387/#37395/#37397/#37402/#37412/#37415/#37419/#37421/#37424),
 * ** 0 / ** 1 (#37269).
 *
 * @group aot-lint
 */
final class NoThrowPowThirtyseventhMulAotTest extends TestCase
{
    public function testPowThirtyseventhOmitsFpowUsesSmul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n ** 37;
        }
        echo work(3), "\n";
        echo work(-2), "\n";
        echo work(0), "\n";
        PHP;
        $this->assertPowThirtyseventh($src, 'work', ['450283905890997363', '-137438953472', '0']);
    }

    public function testBuiltinPowThirtyseventh(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return pow($n, 37);
        }
        echo work(2), "\n";
        PHP;
        $this->assertPowThirtyseventh($src, 'work', ['137438953472']);
    }

    public function testPowThirtyseventhOverflowPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n ** 37;
        }
        $v = work(PHP_INT_MAX);
        echo gettype($v), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow37_ov_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow37_ov_'.getmypid().'.bin';
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
        echo work(2, 37), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pow37_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow37_rt_'.getmypid().'.bin';
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
            $this->assertSame(['137438953472'], $runOut);
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
    private function assertPowThirtyseventh(string $src, string $fn, array $expectedRun): void
    {
        $path = sys_get_temp_dir().'/phpc_pow37_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pow37_'.getmypid().'.bin';
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
