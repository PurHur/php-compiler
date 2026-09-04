<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sinh()/cosh()/tanh() via libm match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(sinh|cosh|tanh).
 * LLVM 9 has no llvm.sinh.f64 / llvm.cosh.f64 / llvm.tanh.f64.
 *
 * @group aot-lint
 */
final class NativeHyperbolicLibmAotTest extends TestCase
{
    public function testHyperbolicLiteralsMatchZendAndCallLibm(): void
    {
        $src = <<<'PHP'
        <?php
        echo sinh(0.0), "\n";
        echo sinh(1.0), "\n";
        echo sinh(-1.0), "\n";
        echo cosh(0.0), "\n";
        echo cosh(1.0), "\n";
        echo tanh(0.0), "\n";
        echo tanh(1.0), "\n";
        echo tanh(0.5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_hyp_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_hyp_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\bsinh\b/', $ll);
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\bcosh\b/', $ll);
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\btanh\b/', $ll);
            $this->assertStringNotContainsString('sinh_bridge_entry', $ll);
            $this->assertStringNotContainsString('cosh_bridge_entry', $ll);
            $this->assertStringNotContainsString('tanh_bridge_entry', $ll);
            $this->assertStringNotContainsString('SinhJitHelper', $ll);
            $this->assertStringNotContainsString('CoshJitHelper', $ll);
            $this->assertStringNotContainsString('TanhJitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(8, $runOut);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\sinh(1.0), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\sinh(-1.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\cosh(1.0), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(\tanh(1.0), (float) $runOut[6], 1e-12);
            $this->assertEqualsWithDelta(\tanh(0.5), (float) $runOut[7], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testSinhFloatFormalLoopUsesLibmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += sinh($x) + cosh($x) + tanh($x);
            }
            echo $s, "\n";
        }
        work(0.5, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_hyp_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_hyp_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @sinh\(/', $body);
            $this->assertMatchesRegularExpression('/call double @cosh\(/', $body);
            $this->assertMatchesRegularExpression('/call double @tanh\(/', $body);
            $this->assertStringNotContainsString('sinh_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * (\sinh(0.5) + \cosh(0.5) + \tanh(0.5));
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
