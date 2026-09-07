<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * log($n, $base) with a compile-time-safe base skips ValueError base≤0 and
 * specializes php-src 2/10/1/else paths (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(log).
 *
 * @group aot-lint
 */
final class NoThrowLogProvenBaseAotTest extends TestCase
{
    public function testProvenBase10UsesLog10WithoutValueErrorBranch(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $n): float {
            return log($n, 10.0);
        }
        echo work(100.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_log10_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_log10_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $fnStart = strpos($ll, 'define double @work(double)');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('llvm.log10.f64', $body);
            $this->assertStringNotContainsString('log_base_gt0', $body);
            $this->assertStringNotContainsString('__compiler_jit_raise_value_error', $body);
            $this->assertStringNotContainsString('must be greater than 0', $body);
            // Specialized — no dead log2 / general select tree.
            $this->assertStringNotContainsString('select i1', $body);

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

    public function testProvenBase2UsesLn2DivideWithoutValueErrorBranch(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $n): float {
            return log($n, 2.0);
        }
        echo work(8.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_log2_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_log2_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $fnStart = strpos($ll, 'define double @work(double)');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('llvm.log.f64', $body);
            $this->assertStringContainsString('fdiv', $body);
            $this->assertStringNotContainsString('log_base_gt0', $body);
            $this->assertStringNotContainsString('llvm.log10.f64', $body);
            $this->assertStringNotContainsString('select i1', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertSame(['3'], $runOut);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testRuntimeBaseKeepsValueErrorGuard(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $n, float $b): float {
            return log($n, $b);
        }
        echo work(100.0, 10.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_nothrow_log_rt_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_nothrow_log_rt_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $this->assertFileExists('/tmp/phpc-last.ll');
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $fnStart = strpos($ll, 'define double @work(double');
            $this->assertNotFalse($fnStart, 'missing @work');
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('log_base_gt0', $body);
            $this->assertStringContainsString('__compiler_jit_raise_value_error', $body);

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
}
