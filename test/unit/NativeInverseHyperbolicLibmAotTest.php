<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * asinh()/acosh()/atanh() via libm match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(asinh|acosh|atanh).
 * LLVM 9 has no llvm.asinh.f64 / llvm.acosh.f64 / llvm.atanh.f64.
 *
 * @group aot-lint
 */
final class NativeInverseHyperbolicLibmAotTest extends TestCase
{
    public function testInverseHyperbolicLiteralsMatchZendAndCallLibm(): void
    {
        $src = <<<'PHP'
        <?php
        echo asinh(0.0), "\n";
        echo asinh(1.0), "\n";
        echo asinh(-1.0), "\n";
        echo acosh(1.0), "\n";
        echo acosh(2.0), "\n";
        echo atanh(0.0), "\n";
        echo atanh(0.5), "\n";
        echo atanh(-0.5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_invhyp_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_invhyp_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\basinh\b/', $ll);
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\bacosh\b/', $ll);
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\batanh\b/', $ll);
            $this->assertStringNotContainsString('asinh_bridge_entry', $ll);
            $this->assertStringNotContainsString('acosh_bridge_entry', $ll);
            $this->assertStringNotContainsString('atanh_bridge_entry', $ll);
            $this->assertStringNotContainsString('AsinhJitHelper', $ll);
            $this->assertStringNotContainsString('AcoshJitHelper', $ll);
            $this->assertStringNotContainsString('AtanhJitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(8, $runOut);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\asinh(1.0), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\asinh(-1.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\acosh(2.0), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(\atanh(0.5), (float) $runOut[6], 1e-12);
            $this->assertEqualsWithDelta(\atanh(-0.5), (float) $runOut[7], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testAsinhFloatFormalLoopUsesLibmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += asinh($x) + acosh($x + 1.0) + atanh($x * 0.5);
            }
            echo $s, "\n";
        }
        work(0.5, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_invhyp_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_invhyp_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @asinh\(/', $body);
            $this->assertMatchesRegularExpression('/call double @acosh\(/', $body);
            $this->assertMatchesRegularExpression('/call double @atanh\(/', $body);
            $this->assertStringNotContainsString('asinh_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * (\asinh(0.5) + \acosh(1.5) + \atanh(0.25));
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
