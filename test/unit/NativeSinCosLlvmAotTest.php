<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sin()/cos() via llvm.sin.f64 / llvm.cos.f64 match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(sin) / PHP_FUNCTION(cos).
 *
 * @group aot-lint
 */
final class NativeSinCosLlvmAotTest extends TestCase
{
    public function testSinCosLiteralsMatchZendAndUseLlvmIntrinsics(): void
    {
        $src = <<<'PHP'
        <?php
        echo sin(0.0), "\n";
        echo cos(0.0), "\n";
        echo sin(1.0), "\n";
        echo cos(1.0), "\n";
        echo sin(M_PI / 2.0), "\n";
        echo cos(M_PI / 3.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_sin_cos_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_sin_cos_lit_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.sin.f64', $ll);
            $this->assertStringContainsString('llvm.cos.f64', $ll);
            $this->assertStringNotContainsString('sin_bridge_entry', $ll);
            $this->assertStringNotContainsString('cos_bridge_entry', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(6, $runOut);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\sin(1.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\cos(1.0), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\sin(\M_PI / 2.0), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(\cos(\M_PI / 3.0), (float) $runOut[5], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testSinCosFloatFormalLoopUsesIntrinsicsWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += sin($x);
                $s += cos($x);
            }
            echo $s, "\n";
        }
        work(0.5, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_sin_cos_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_sin_cos_formal_'.getmypid().'.bin';
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

            $this->assertStringContainsString('llvm.sin.f64', $body);
            $this->assertStringContainsString('llvm.cos.f64', $body);
            $this->assertStringNotContainsString('sin_bridge_entry', $body);
            $this->assertStringNotContainsString('cos_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * (\sin(0.5) + \cos(0.5));
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
