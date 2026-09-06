<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed int unary − keeps PHP_INT_MIN cold path off the value-box prologue (#36386).
 *
 * Hot path: i64 negate + phi — no entryAllocaValueBox / __value__writeLong per site.
 * INT_MIN stores f64 only; materialize boxes for var_dump / mixed return.
 * php-src: Zend/zend_operators.c zendi_negate_function.
 *
 * @group aot-lint
 */
final class NativeLongUnaryMinusHeapLazyAotTest extends TestCase
{
    public function testTypedNegHotPathHasNoValueBoxAlloca(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function neg(int $n): int {
            return -$n;
        }
        echo neg(5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_unary_neg_lazy_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_unary_neg_lazy_'.getmypid().'.bin';
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

            $sig = null;
            if (preg_match('/define i64 @neg\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define i64 @neg');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringNotContainsString('alloca %__value__', $body);
            $this->assertStringNotContainsString('__value__writeLong', $body);
            $this->assertStringNotContainsString('__value__writeDouble', $body);
            $this->assertStringNotContainsString('__value__readLong', $body);
            $this->assertMatchesRegularExpression('/phi i64/', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['-5'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testIntMinNegateMaterializeMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function show_neg(int $n): void {
            var_dump(-$n);
        }
        show_neg(5);
        show_neg(PHP_INT_MIN);
        PHP;
        $path = sys_get_temp_dir().'/phpc_unary_neg_mat_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_unary_neg_mat_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zend, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zend));
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aot));
            $this->assertSame($zend, $aot);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testStrictIntReturnOfIntMinNegateTypeErrors(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function neg_int(int $n): int {
            return -$n;
        }
        try {
            var_dump(neg_int(5));
        } catch (Throwable $e) {
            echo 'E1 ', get_class($e), "\n";
        }
        try {
            var_dump(neg_int(PHP_INT_MIN));
        } catch (Throwable $e) {
            echo 'E2 ', get_class($e), "\n";
        }
        PHP;
        $path = sys_get_temp_dir().'/phpc_unary_neg_ret_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_unary_neg_ret_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aot));
            $joined = implode("\n", $aot);
            // Exact negate stays int; INT_MIN must TypeError under strict_types.
            // Standalone pending-throw may surface the dummy i64 before the catch
            // (same shape as NativeLongDivHeapLazyAotTest).
            $this->assertStringContainsString('int(-5)', $joined);
            $this->assertStringContainsString('E2 TypeError', $joined);
            $this->assertStringNotContainsString('E1 ', $joined);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
