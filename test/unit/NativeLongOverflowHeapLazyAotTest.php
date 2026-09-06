<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed int ±/× keeps overflow cold path off the hot prologue (#36386).
 *
 * Hot path: llvm.*.with.overflow + i64 phi — no entryAllocaValueBox / TYPE_NULL
 * init per arith site. Overflow materializes a __value__ box only when boxed
 * (var_dump / untyped consumer). php-src: Zend/zend_operators.h ZEND_SIGNED_*_OVERFLOW.
 *
 * @group aot-lint
 */
final class NativeLongOverflowHeapLazyAotTest extends TestCase
{
    public function testFiboRHotPathHasNoValueBoxAlloca(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function fibo_r(int $n): int {
            return $n < 2 ? $n : fibo_r($n - 1) + fibo_r($n - 2);
        }
        echo fibo_r(10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_fibo_ov_lazy_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fibo_ov_lazy_'.getmypid().'.bin';
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
            if (preg_match('/define i64 @fibo_r\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define i64 @fibo_r');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringNotContainsString('alloca %__value__', $body);
            $this->assertStringNotContainsString('__value__writeDouble', $body);
            $this->assertStringContainsString('llvm.ssub.with.overflow.i64', $body);
            $this->assertStringContainsString('llvm.sadd.with.overflow.i64', $body);
            $this->assertMatchesRegularExpression('/phi i64/', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['55'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testOverflowStillPromotesWhenMaterialized(): void
    {
        $src = <<<'PHP'
        <?php
        var_dump(PHP_INT_MAX + 1);
        var_dump(PHP_INT_MAX - -1);
        PHP;
        $path = sys_get_temp_dir().'/phpc_ov_mat_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_ov_mat_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString('float(9.223372036854', $joined);
            $this->assertStringNotContainsString('int(-9223372036854775808)', $joined);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
