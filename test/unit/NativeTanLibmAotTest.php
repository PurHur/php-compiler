<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tan() via libm tan(3) matches Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(tan).
 * LLVM 9 has no llvm.tan.f64 (unlike sin/cos).
 *
 * @group aot-lint
 */
final class NativeTanLibmAotTest extends TestCase
{
    public function testTanLiteralsMatchZendAndCallLibm(): void
    {
        $src = <<<'PHP'
        <?php
        echo tan(0.0), "\n";
        echo tan(1.0), "\n";
        echo tan(M_PI / 4.0), "\n";
        echo tan(0.5), "\n";
        echo tan(2.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_tan_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_tan_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/\b(call|declare)\b.*\btan\b/', $ll);
            $this->assertStringNotContainsString('tan_bridge_entry', $ll);
            $this->assertStringNotContainsString('TanJitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(5, $runOut);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\tan(1.0), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\tan(\M_PI / 4.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\tan(0.5), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\tan(2.0), (float) $runOut[4], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testTanFloatFormalLoopUsesLibmWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += tan($x);
            }
            echo $s, "\n";
        }
        work(0.5, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_tan_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_tan_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/call double @tan\(/', $body);
            $this->assertStringNotContainsString('tan_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * \tan(0.5);
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
