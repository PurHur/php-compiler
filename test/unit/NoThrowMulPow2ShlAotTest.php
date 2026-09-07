<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long {@code * 2^k} ({@code k} in 1..62) lowers to {@code shl}
 * with {@code ashr} round-trip overflow → float promote (omit
 * {@code llvm.smul.with.overflow}) (#36386).
 *
 * php-src: Zend/zend_operators.c mul_function / ZEND_LONG_MUL_OVERFLOW.
 * Peer: {@code * -1} (#37222), {@code * 1} identity (#37200).
 *
 * @group aot-lint
 */
final class NoThrowMulPow2ShlAotTest extends TestCase
{
    public function testMulTwoUsesShlKeepsOverflowPromote(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * 2;
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul2_shl_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul2_shl_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertStringNotContainsString('mul i64', $body);
            $this->assertStringContainsString('shl i64', $body);
            $this->assertStringContainsString('ashr i64', $body);
            $this->assertStringContainsString('native_long_mul_pow2_', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['14'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testTwoMulUsesShl(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return 2 * $n;
        }
        echo work(11), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_2mul_shl_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_2mul_shl_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertStringContainsString('shl i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['22'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMulEightUsesShlByThree(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n * 8;
        }
        echo work(5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul8_shl_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul8_shl_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('llvm.smul.with.overflow.i64', $body);
            $this->assertMatchesRegularExpression('/shl i64 .*, 3\b/', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['40'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testMulTwoIntMaxPromotesToFloat(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n) {
            return $n * 2;
        }
        $r = work(PHP_INT_MAX);
        echo gettype($r), ':', $r, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul2_ov_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul2_ov_'.getmypid().'.bin';
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

    public function testRuntimeFactorKeepsSmul(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n * $m;
        }
        echo work(6, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mul2_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mul2_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.smul.with.overflow.i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['12'], $runOut);
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
