<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long same-operand {@code /} and {@code %} (#36386):
 * - {@code $n / $n} → {@code 1} (omit {@code sdiv}/{@code srem}/exactness;
 *   keep {@code DivisionByZeroError} when {@code n == 0})
 * - {@code $n % $n} → {@code 0} (omit {@code srem} / neg-one PHI; keep %0)
 *
 * php-src: Zend/zend_operators.c div_function / mod_function.
 * Peer: same-operand {@code -}/{@code +} (#37238 / #37242), compile-time
 * {@code / 1} / {@code % ±1}.
 *
 * @group aot-lint
 */
final class NoThrowDivModSameOperandAotTest extends TestCase
{
    public function testDivSelfFoldsToOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n / $n;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandDivFold($src, 'work', ['1']);
    }

    public function testDivSelfIntMinFoldsToOne(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n / $n;
        }
        echo work(PHP_INT_MIN), "\n";
        PHP;
        $this->assertSameOperandDivFold($src, 'work', ['1']);
    }

    public function testDivSelfKeepsZeroGuardWithoutSdiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n / $n;
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_divmod_same_div0_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_divmod_same_div0_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('sdiv', $body);
            $this->assertStringNotContainsString('srem', $body);
            $this->assertStringContainsString('numdiv_long_err', $body);
            $this->assertStringContainsString('icmp eq i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['1'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDistinctOperandsKeepDiv(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n / $m;
        }
        echo work(10, 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_divmod_same_div_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_divmod_same_div_rt_'.getmypid().'.bin';
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
                false !== strpos($body, 'sdiv')
                    || false !== strpos($body, 'srem'),
                "expected sdiv/srem in IR:\n".$body
            );
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

    public function testModSelfFoldsToZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n % $n;
        }
        echo work(7), "\n";
        PHP;
        $this->assertSameOperandModFold($src, 'work', ['0']);
    }

    public function testModSelfIntMinFoldsToZero(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n % $n;
        }
        echo work(PHP_INT_MIN), "\n";
        PHP;
        $this->assertSameOperandModFold($src, 'work', ['0']);
    }

    public function testModSelfKeepsZeroGuardWithoutSrem(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n): int {
            return $n % $n;
        }
        echo work(7), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_divmod_same_mod0_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_divmod_same_mod0_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('srem', $body);
            $this->assertStringContainsString('numdiv_long_err', $body);
            $this->assertStringContainsString('icmp eq i64', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['0'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDistinctOperandsKeepMod(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n % $m;
        }
        echo work(11, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_divmod_same_mod_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_divmod_same_mod_rt_'.getmypid().'.bin';
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
            $this->assertStringContainsString('srem', $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['2'], $runOut);
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
    private function assertSameOperandDivFold(
        string $src,
        string $fn,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_divmod_same_div_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_divmod_same_div_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('sdiv', $body);
            $this->assertStringNotContainsString('srem', $body);
            $this->assertStringContainsString('i64 1', $body);
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

    /**
     * @param list<string> $expectedRun
     */
    private function assertSameOperandModFold(
        string $src,
        string $fn,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_divmod_same_mod_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_divmod_same_mod_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('srem', $body);
            $this->assertStringContainsString('i64 0', $body);
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
        $fnStart = strpos($ll, 'define i64 @'.$fn.'(i64');
        if (false === $fnStart) {
            $fnStart = strpos($ll, 'define %__value__ @'.$fn.'(i64');
        }
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
