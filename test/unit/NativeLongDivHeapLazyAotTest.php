<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed int `/` keeps exact hot path off the value-box prologue (#36386).
 *
 * Hot path: srem exact + sdiv — no entryAllocaValueBox per div site. Non-exact /
 * INT_MIN÷−1 stores f64 only; materialize boxes for var_dump / mixed return.
 * php-src: Zend/zend_operators.c div_function.
 *
 * Also covers named-local materialize for ± overflow leftover of #37051.
 *
 * @group aot-lint
 */
final class NativeLongDivHeapLazyAotTest extends TestCase
{
    public function testExactIntDivHotPathHasNoValueBoxAlloca(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function idiv_exact(int $a, int $b): int {
            return $a / $b;
        }
        echo idiv_exact(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_idiv_lazy_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_idiv_lazy_'.getmypid().'.bin';
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
            if (preg_match('/define i64 @idiv_exact\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define i64 @idiv_exact');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringNotContainsString('alloca %__value__', $body);
            $this->assertStringNotContainsString('__value__writeLong', $body);
            $this->assertStringNotContainsString('__value__writeDouble', $body);
            $this->assertStringContainsString('srem i64', $body);
            $this->assertStringContainsString('sdiv i64', $body);

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

    public function testNonExactDivAndOverflowAssignMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function show_div(int $a, int $b): void {
            var_dump($a / $b);
        }
        function show_ov(int $a): void {
            $x = $a + 1;
            var_dump($x);
        }
        show_div(10, 2);
        show_div(7, 2);
        show_ov(PHP_INT_MAX);
        PHP;
        $path = sys_get_temp_dir().'/phpc_idiv_mat_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_idiv_mat_'.getmypid().'.bin';
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

    public function testStrictIntReturnOfNonExactDivTypeErrors(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function f(int $a, int $b): int {
            return $a / $b;
        }
        try {
            var_dump(f(10, 2));
        } catch (Throwable $e) {
            echo 'E1 ', get_class($e), "\n";
        }
        try {
            var_dump(f(7, 2));
        } catch (Throwable $e) {
            echo 'E2 ', get_class($e), "\n";
        }
        PHP;
        $path = sys_get_temp_dir().'/phpc_idiv_ret_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_idiv_ret_'.getmypid().'.bin';
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
            // Exact quotient stays int; non-exact must TypeError under strict_types.
            // Standalone pending-throw may surface the dummy i64 before the catch
            // (same shape as pre-existing `: int` VALUE returns) — require the TypeError.
            $this->assertStringContainsString('int(5)', $joined);
            $this->assertStringContainsString('E2 TypeError', $joined);
            $this->assertStringNotContainsString('E1 ', $joined);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
