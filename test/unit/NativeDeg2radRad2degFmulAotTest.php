<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * deg2rad()/rad2deg() via inline fmul match Zend (#36386).
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(deg2rad|rad2deg).
 *
 * @group aot-lint
 */
final class NativeDeg2radRad2degFmulAotTest extends TestCase
{
    public function testDeg2radRad2degLiteralsMatchZendAndUseFmul(): void
    {
        $src = <<<'PHP'
        <?php
        echo deg2rad(0.0), "\n";
        echo deg2rad(180.0), "\n";
        echo deg2rad(90.0), "\n";
        echo deg2rad(-45.0), "\n";
        echo rad2deg(0.0), "\n";
        echo rad2deg(M_PI), "\n";
        echo rad2deg(M_PI_2), "\n";
        echo rad2deg(-M_PI_4), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_deg2rad_lit_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_deg2rad_lit_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression('/fmul\b.*double/', $ll);
            $this->assertStringNotContainsString('deg2rad_bridge_entry', $ll);
            $this->assertStringNotContainsString('rad2deg_bridge_entry', $ll);
            $this->assertStringNotContainsString('Deg2radJitHelper', $ll);
            $this->assertStringNotContainsString('Rad2degJitHelper', $ll);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $this->assertCount(8, $runOut);
            $this->assertEqualsWithDelta(\deg2rad(0.0), (float) $runOut[0], 1e-12);
            $this->assertEqualsWithDelta(\deg2rad(180.0), (float) $runOut[1], 1e-12);
            $this->assertEqualsWithDelta(\deg2rad(90.0), (float) $runOut[2], 1e-12);
            $this->assertEqualsWithDelta(\deg2rad(-45.0), (float) $runOut[3], 1e-12);
            $this->assertEqualsWithDelta(\rad2deg(0.0), (float) $runOut[4], 1e-12);
            $this->assertEqualsWithDelta(\rad2deg(\M_PI), (float) $runOut[5], 1e-12);
            $this->assertEqualsWithDelta(\rad2deg(\M_PI_2), (float) $runOut[6], 1e-12);
            $this->assertEqualsWithDelta(\rad2deg(-\M_PI_4), (float) $runOut[7], 1e-12);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testDeg2radFloatFormalLoopUsesFmulWithoutHelperBridge(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(float $d, int $n): void {
            $s = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $s += deg2rad($d);
            }
            echo $s, "\n";
        }
        work(180.0, 10);
        PHP;
        $path = sys_get_temp_dir().'/phpc_deg2rad_formal_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_deg2rad_formal_'.getmypid().'.bin';
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

            $this->assertMatchesRegularExpression('/fmul\b/', $body);
            $this->assertStringNotContainsString('deg2rad_bridge_entry', $body);
            $this->assertStringNotContainsString('call double @phpc_deg2rad', $body);
            $this->assertStringNotContainsString('phpc_jit_has_throw_pending', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            $expected = 10.0 * \deg2rad(180.0);
            $this->assertEqualsWithDelta($expected, (float) $runOut[0], 1e-9);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
