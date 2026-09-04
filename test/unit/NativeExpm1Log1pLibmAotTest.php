<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * expm1()/log1p() via libm match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(expm1|log1p).
 * LLVM 9 has no llvm.expm1.f64 / llvm.log1p.f64.
 *
 * @group aot-lint
 */
final class NativeExpm1Log1pLibmAotTest extends TestCase
{
    public function testExpm1Log1pLiteralsMatchZendAndCallLibm(): void
    {
        $src = <<<'PHP'
        <?php
        echo expm1(0.0), "\n";
        echo expm1(1.0), "\n";
        echo expm1(-1.0), "\n";
        echo log1p(0.0), "\n";
        echo log1p(1.0), "\n";
        echo log1p(-0.5), "\n";
        echo log1p(0.5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_expm1_log1p_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_expm1_log1p_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\bexpm1\b/', $ll);
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\blog1p\b/', $ll);
            $this->assertStringNotContainsString('expm1_bridge_entry', $ll);
            $this->assertStringNotContainsString('log1p_bridge_entry', $ll);
            $this->assertStringNotContainsString('Expm1JitHelper', $ll);
            $this->assertStringNotContainsString('Log1pJitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(7, $runOut);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\expm1(1.0), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\expm1(-1.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\log1p(1.0), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(\log1p(-0.5), (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(\log1p(0.5), (float) $runOut[6], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testExpm1Log1pFloatFormalLoopUsesLibmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += expm1($x) + log1p($x);
            }
            echo $s, "\n";
        }
        work(0.25, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_expm1_log1p_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_expm1_log1p_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @expm1\(/', $body);
            $this->assertMatchesRegularExpression('/call double @log1p\(/', $body);
            $this->assertStringNotContainsString('expm1_bridge_entry', $body);
            $this->assertStringNotContainsString('log1p_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * (\expm1(0.25) + \log1p(0.25));
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
