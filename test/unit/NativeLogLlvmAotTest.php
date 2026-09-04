<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * log()/log10() via llvm.log.f64 / llvm.log10.f64 match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(log) / PHP_FUNCTION(log10).
 *
 * @group aot-lint
 */
final class NativeLogLlvmAotTest extends TestCase
{
    public function testLogLog10LiteralsMatchZendAndUseLlvmIntrinsics(): void
    {
        $src = <<<'PHP'
        <?php
        echo log(1.0), "\n";
        echo log(M_E), "\n";
        echo log(0.5), "\n";
        echo log(2.0), "\n";
        echo log10(1.0), "\n";
        echo log10(10.0), "\n";
        echo log10(100.0), "\n";
        echo log(100.0, 10.0), "\n";
        echo log(8.0, 2.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_log_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_log_lit_'.getmypid().'.bin';
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
            $this->assertStringContainsString('llvm.log.f64', $ll);
            $this->assertStringContainsString('llvm.log10.f64', $ll);
            $this->assertStringNotContainsString('log_bridge_entry', $ll);
            $this->assertStringNotContainsString('log10_bridge_entry', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(9, $runOut);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\log(0.5), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\log(2.0), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(0.0, (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(1.0, (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[6], 1e-12);
            $this->assertEqualsWithDelta(2.0, (float) $runOut[7], 1e-12);
            $this->assertEqualsWithDelta(3.0, (float) $runOut[8], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testLogFloatFormalLoopUsesIntrinsicWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $x, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += log($x);
                $s += log10($x);
            }
            echo $s, "\n";
        }
        work(2.0, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_log_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_log_formal_'.getmypid().'.bin';
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

            $this->assertStringContainsString('llvm.log.f64', $body);
            $this->assertStringContainsString('llvm.log10.f64', $body);
            $this->assertStringNotContainsString('log_bridge_entry', $body);
            $this->assertStringNotContainsString('log10_bridge_entry', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * (\log(2.0) + \log10(2.0));
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
