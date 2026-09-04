<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * sqrt() via llvm.sqrt.f64 matches Zend; float formals stay native (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(sqrt) → zend_csqrt.
 *
 * @group aot-lint
 */
final class NativeFloatFormalLoopAotTest extends TestCase
{
    public function testSqrtLiteralMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        echo sqrt(2.5), "\n";
        echo sqrt(4.0), "\n";
        echo sqrt(9.0), "\n";
        echo sqrt(0.25), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_sqrt_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_sqrt_lit_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.sqrt.f64', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(4, $runOut);
            $this->assertEqualsWithDelta(1.5811388300842, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(3.0, (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(0.5, (float) $runOut[3], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testFloatFormalInLoopStaysNativeDoubleAndSqrtMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): float {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += $x;
                $s += sqrt($x);
            }
            return $s;
        }
        echo work(2.5, 10), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_float_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_float_formal_'.getmypid().'.bin';
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

            $sig = null;
            if (preg_match('/define double @work\([^\)]*\)/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing define double @work');
            $this->assertStringNotContainsString('%__value__', $sig);

            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertStringNotContainsString('__value__writeDouble', $body);
            $this->assertStringNotContainsString('__value__readDouble', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);
            $this->assertStringContainsString('llvm.sqrt.f64', $body);
            $this->assertLessThan(
                80,
                substr_count($body, '__value__'),
                'hot loop should not box float formal through __value__'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // 10 * (2.5 + sqrt(2.5))
            $this->assertEqualsWithDelta(40.811388300841, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
