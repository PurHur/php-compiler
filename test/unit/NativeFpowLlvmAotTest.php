<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * pow() float arm via llvm.pow.f64 matches Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(fpow) / pow_function.
 * Default language profile is 8.4.0-dev which withholds fpow() registration
 * ({@see CompilerVersion::supportsFpow}); pow() always shares MathFpow.
 *
 * @group aot-lint
 */
final class NativeFpowLlvmAotTest extends TestCase
{
    public function testPowLiteralsMatchZendAndUseLlvmIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        echo pow(2.0, 3.0), "\n";
        echo pow(4.0, 0.5), "\n";
        echo pow(2.0, -3.0), "\n";
        echo pow(2.5, 1.5), "\n";
        echo pow(10.0, 0.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_fpow_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fpow_lit_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.pow.f64', $ll);
            $this->assertStringNotContainsString('fpow_bridge_entry', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(5, $runOut);
            $this->assertEqualsWithDelta(\pow(2.0, 3.0), (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\pow(4.0, 0.5), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\pow(2.0, -3.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\pow(2.5, 1.5), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[4], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testPowFloatFormalLoopUsesIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, float $y, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += pow($x, $y);
            }
            echo $s, "\n";
        }
        work(2.0, 3.0, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_fpow_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fpow_formal_'.getmypid().'.bin';
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

            $this->assertStringContainsString('llvm.pow.f64', $body);
            $this->assertStringNotContainsString('fpow_bridge_entry', $body);
            // JitPow inserts throw-pending probes around the float arm; unlike
            // unary exp()/sin() builtins that lower via JitFdiv::lowerSingleOperand.

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * \pow(2.0, 3.0);
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testFpowUnderProfile84UsesLlvmIntrinsic(): void
    {
        $src = <<<'PHP'
        <?php
        echo fpow(2.0, 3.0), "\n";
        echo fpow(4.0, 0.5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_fpow84_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_fpow84_lit_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            putenv('PHP_COMPILER_PROFILE=8.4');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');
            $this->assertStringContainsString('llvm.pow.f64', $ll);
            $this->assertStringNotContainsString('fpow_bridge_entry', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(2, $runOut);
            $this->assertEqualsWithDelta(\pow(2.0, 3.0), (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\pow(4.0, 0.5), (float) $runOut[1], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            putenv('PHP_COMPILER_PROFILE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
