<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * atan2() via libm matches Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(atan2).
 * LLVM 9 has no llvm.atan2.f64.
 *
 * @group aot-lint
 */
final class NativeAtan2LibmAotTest extends TestCase
{
    public function testAtan2LiteralsMatchZendAndCallLibm(): void
    {
        $src = <<<'PHP'
        <?php
        echo atan2(0.0, 1.0), "\n";
        echo atan2(1.0, 1.0), "\n";
        echo atan2(-1.0, 1.0), "\n";
        echo atan2(1.0, -1.0), "\n";
        echo atan2(-1.0, -1.0), "\n";
        echo atan2(1.0, 0.0), "\n";
        echo atan2(3.0, 4.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_atan2_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_atan2_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\batan2\b/', $ll);
            $this->assertStringNotContainsString('atan2_bridge_entry', $ll);
            $this->assertStringNotContainsString('Atan2JitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(7, $runOut);
            $this->assertEqualsWithDelta(\atan2(0.0, 1.0), (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\atan2(1.0, 1.0), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\atan2(-1.0, 1.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\atan2(1.0, -1.0), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\atan2(-1.0, -1.0), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(\atan2(1.0, 0.0), (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(\atan2(3.0, 4.0), (float) $runOut[6], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testAtan2FloatFormalLoopUsesLibmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $y, float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += atan2($y, $x);
            }
            echo $s, "\n";
        }
        work(3.0, 4.0, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_atan2_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_atan2_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @atan2\(/', $body);
            $this->assertStringNotContainsString('atan2_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * \atan2(3.0, 4.0);
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
