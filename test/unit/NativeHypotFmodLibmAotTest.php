<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * hypot()/fmod() via libm match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(hypot|fmod).
 * LLVM 9 has no llvm.hypot.f64 / llvm.fmod.f64.
 *
 * @group aot-lint
 */
final class NativeHypotFmodLibmAotTest extends TestCase
{
    public function testHypotFmodLiteralsMatchZendAndCallLibm(): void
    {
        $src = <<<'PHP'
        <?php
        echo hypot(3.0, 4.0), "\n";
        echo hypot(0.0, 5.0), "\n";
        echo hypot(5.0, 12.0), "\n";
        echo fmod(5.5, 2.0), "\n";
        echo fmod(-1.5, 1.2), "\n";
        echo fmod(5.7, 1.3), "\n";
        echo fmod(-7.0, 3.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_hypot_fmod_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_hypot_fmod_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\bhypot\b/', $ll);
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\bfmod\b/', $ll);
            $this->assertStringNotContainsString('hypot_bridge_entry', $ll);
            $this->assertStringNotContainsString('fmod_bridge_entry', $ll);
            $this->assertStringNotContainsString('HypotJitHelper', $ll);
            $this->assertStringNotContainsString('FmodJitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(7, $runOut);
            $this->assertEqualsWithDelta(5.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(5.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(13.0, (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\fmod(5.5, 2.0), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\fmod(-1.5, 1.2), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(\fmod(5.7, 1.3), (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(\fmod(-7.0, 3.0), (float) $runOut[6], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testHypotFmodFloatFormalLoopUsesLibmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, float $y, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += hypot($x, $y) + fmod($x, $y);
            }
            echo $s, "\n";
        }
        work(3.0, 4.0, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_hypot_fmod_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_hypot_fmod_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @hypot\(/', $body);
            $this->assertMatchesRegularExpression('/call double @fmod\(/', $body);
            $this->assertStringNotContainsString('hypot_bridge_entry', $body);
            $this->assertStringNotContainsString('fmod_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * (\hypot(3.0, 4.0) + \fmod(3.0, 4.0));
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
