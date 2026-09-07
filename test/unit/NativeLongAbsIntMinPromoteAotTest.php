<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed int abs() promotes PHP_INT_MIN → float; compile-time ≠ INT_MIN skips
 * the promote arm (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(abs) IS_LONG / ZEND_LONG_MIN.
 * Peer: unary − INT_MIN promote (NativeLongUnaryMinusHeapLazyAotTest).
 *
 * @group aot-lint
 */
final class NativeLongAbsIntMinPromoteAotTest extends TestCase
{
    public function testTypedAbsHotPathHasNoValueBoxAlloca(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return abs($n);
        }
        echo work(-5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_abs_lazy_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_abs_lazy_'.getmypid().'.bin';
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

            // Runtime typed abs keeps INT_MIN promote off the hot select path.
            $this->assertStringContainsString('abs_long_int_min', $body);
            $this->assertStringContainsString('abs_long_ok', $body);
            $this->assertMatchesRegularExpression('/phi i64/', $body);
            // Hot arm is bare select — not a boxed writeLong per site.
            $this->assertStringNotContainsString('__value__writeLong', $body);
            $this->assertStringNotContainsString('__value__writeDouble', $body);

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

    public function testCompileTimeAbsOmitsIntMinPromote(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return abs(-7);
        }
        echo work(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_abs_ct_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_abs_ct_'.getmypid().'.bin';
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

            // Proven ≠ INT_MIN — no runtime promote arm (fold to 7).
            $this->assertStringNotContainsString('abs_long_int_min', $body);
            $this->assertStringNotContainsString('abs_long_ok', $body);
            $this->assertTrue(
                false !== strpos($body, 'i64 7')
                || false !== strpos($body, 'ret i64 7'),
                "expected folded abs(-7) → 7\n".$body
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['7'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testIntMinAbsMaterializeMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function show_abs(int $n): void {
            var_dump(abs($n));
        }
        show_abs(-5);
        show_abs(PHP_INT_MIN);
        PHP;
        $path = sys_get_temp_dir().'/phpc_abs_mat_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_abs_mat_'.getmypid().'.bin';
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

    public function testStrictIntReturnOfIntMinAbsTypeErrors(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function abs_int(int $n): int {
            return abs($n);
        }
        try {
            var_dump(abs_int(-5));
        } catch (Throwable $e) {
            echo 'E1 ', get_class($e), "\n";
        }
        try {
            var_dump(abs_int(PHP_INT_MIN));
        } catch (Throwable $e) {
            echo 'E2 ', get_class($e), "\n";
        }
        PHP;
        $path = sys_get_temp_dir().'/phpc_abs_ret_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_abs_ret_'.getmypid().'.bin';
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
            $this->assertStringContainsString('int(5)', $joined);
            $this->assertStringContainsString('E2 TypeError', $joined);
            $this->assertStringNotContainsString('E1 ', $joined);
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    private function functionBody(string $ll, string $fn): string
    {
        $this->assertSame(1, preg_match(
            '/define [^\n]*@'.preg_quote($fn, '/').'\([^\)]*\)[^\n]*\{/',
            $ll,
            $m,
            PREG_OFFSET_CAPTURE
        ), 'missing @'.$fn);
        $fnStart = (int) $m[0][1];
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
