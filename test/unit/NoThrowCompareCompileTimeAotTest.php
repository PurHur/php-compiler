<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Typed native-long compare when both operands are compile-time longs (#36386):
 * distinct literal SSA temps ({@code 7 === 7}) that same-operand miss
 * (different Value wrappers) fold to const bool / spaceship −1|0|1
 * (omit {@code icmp} / resource-identity CFG).
 *
 * php-src: Zend/zend_operators.c compare_function / is_identical_function /
 * is_equal_function / zend_compare_longs.
 * Peer: same-operand compare (#37248).
 *
 * @group aot-lint
 */
final class NoThrowCompareCompileTimeAotTest extends TestCase
{
    public function testIdenticalLiteralsFoldTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 7 === 7 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', true, ['1']);
    }

    public function testIdenticalLiteralsFoldFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 7 === 3 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', false, ['0']);
    }

    public function testEqualLiteralsFoldTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 7 == 7 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', true, ['1']);
    }

    public function testNotEqualLiteralsFoldTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 7 != 3 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', true, ['1']);
    }

    public function testSmallerLiteralsFoldTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 3 < 7 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', true, ['1']);
    }

    public function testGreaterLiteralsFoldFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 3 > 7 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', false, ['0']);
    }

    public function testSmallerOrEqualLiteralsFoldTrue(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 7 <= 7 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', true, ['1']);
    }

    public function testGreaterOrEqualLiteralsFoldFalse(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(): int {
            return 3 >= 7 ? 1 : 0;
        }
        echo work(), "\n";
        PHP;
        $this->assertCompileTimeBoolFold($src, 'work', false, ['0']);
    }

    public function testSpaceshipLiteralsFoldPositive(): void
    {
        $this->assertSpaceshipFold(7, 3, 1);
    }

    public function testSpaceshipLiteralsFoldNegative(): void
    {
        $this->assertSpaceshipFold(3, 7, -1);
    }

    public function testSpaceshipLiteralsFoldZero(): void
    {
        $this->assertSpaceshipFold(7, 7, 0);
    }

    public function testRuntimeOperandsKeepCompare(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, int $m): int {
            return $n === $m ? 1 : 0;
        }
        echo work(7, 7), "\n";
        echo work(7, 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cmp_ct_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cmp_ct_rt_'.getmypid().'.bin';
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
                false !== strpos($body, 'icmp ')
                    || false !== strpos($body, 'nativeLongEqual')
                    || false !== strpos($body, 'resource'),
                "expected compare IR in:\n".$body
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['1', '0'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    private function assertSpaceshipFold(int $left, int $right, int $expected): void
    {
        $src = <<<PHP
        <?php
        declare(strict_types=1);
        function work(): int {
            return {$left} <=> {$right};
        }
        echo work(), "\\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cmp_ct_ship_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cmp_ct_ship_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('icmp ', $body);
            $this->assertStringContainsString('i64 '.$expected, $body);
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame([(string) $expected], $runOut);
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
    private function assertCompileTimeBoolFold(
        string $src,
        string $fn,
        bool $expectTrueConst,
        array $expectedRun
    ): void {
        $path = sys_get_temp_dir().'/phpc_cmp_ct_'.($expectTrueConst ? 't' : 'f').'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cmp_ct_'.($expectTrueConst ? 't' : 'f').'_'.getmypid().'.bin';
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
            $this->assertStringNotContainsString('icmp ', $body);
            $this->assertStringNotContainsString('__compiler_is_resource', $body);
            if ($expectTrueConst) {
                $this->assertTrue(
                    false !== strpos($body, 'i1 true')
                        || false !== strpos($body, 'i1 1'),
                    "expected const true in IR:\n".$body
                );
            } else {
                $this->assertTrue(
                    false !== strpos($body, 'i1 false')
                        || false !== strpos($body, 'i1 0'),
                    "expected const false in IR:\n".$body
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
        $fnStart = strpos($ll, 'define i64 @'.$fn.'(');
        if (false === $fnStart) {
            $fnStart = strpos($ll, 'define %__value__ @'.$fn.'(');
        }
        $this->assertNotFalse($fnStart, 'missing @'.$fn);
        $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);

        return false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
    }
}
