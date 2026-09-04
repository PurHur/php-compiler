<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * abs() via llvm.fabs.f64 / i64 select matches Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(abs).
 *
 * @group aot-lint
 */
final class NativeAbsLlvmAotTest extends TestCase
{
    public function testAbsLiteralsMatchZendAndUseLlvmFabs(): void
    {
        $src = <<<'PHP'
        <?php
        echo abs(-2.5), "\n";
        echo abs(2.5), "\n";
        echo abs(-7), "\n";
        echo abs(0), "\n";
        echo abs(-0.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_abs_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_abs_lit_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.fabs.f64', $ll);
            // long abs is inlined at the call site (select), not only via ABI call
            $this->assertTrue(
                false !== strpos($ll, 'phpc_abs_long')
                || (false !== strpos($ll, 'select i1') && false !== strpos($ll, 'sub i64')),
                'expected llvm long-abs select or phpc_abs_long'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(5, $runOut);
            $this->assertEqualsWithDelta(2.5, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(2.5, (float) $runOut[1], 1e-12);
            $this->assertSame('7', $runOut[2]);
            $this->assertSame('0', $runOut[3]);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[4], 0.0);
            $this->assertSame(
                \unpack('P', \pack('d', 0.0))[1],
                \unpack('P', \pack('d', (float) $runOut[4]))[1],
                'abs(-0.0) must be +0.0 (php-src fabs)'
            );
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testAbsFloatFormalLoopUsesFabsWithoutHelperBridge(): void
    {
        // Avoid `function (): float { return abs(...) }` — abs is int|float at the
        // call boundary and the float-return unbox path segfaults on master too
        // (pre-existing; same with AbsJitHelper). Echo keeps the fabs hot path.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += abs($x);
                $s += abs(-$x);
            }
            echo $s, "\n";
        }
        work(-2.5, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_abs_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_abs_formal_'.getmypid().'.bin';
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
            if (preg_match('/define void @work\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define void @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringContainsString('llvm.fabs.f64', $body);
            $this->assertStringNotContainsString('abs_double_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // 10 * (abs(-2.5) + abs(2.5)) = 10 * 5 = 50
            $this->assertEqualsWithDelta(50.0, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
