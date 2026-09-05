<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded microtime / hrtime / gettimeofday must not lower (#36386). Live
 * results still match Zend on shape checks.
 *
 * php-src: ext/standard/microtime.c, ext/standard/hrtime.c
 *
 * @group aot-lint
 */
final class DiscardedClockGetterElisionAotTest extends TestCase
{
    public function testDiscardedOnlyClockGetterHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                microtime();
                microtime(true);
                hrtime();
                hrtime(true);
                gettimeofday();
                gettimeofday(true);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_clock_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_clock_only_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@only_discarded\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @only_discarded');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertSame(
                0,
                preg_match_all('/__compiler_microtime_(string|float)\b/', $body),
                'discarded microtime must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_hrtime_(ns|pair)\b/', $body),
                'discarded hrtime must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_gettimeofday_(array|float)\b/', $body),
                'discarded gettimeofday must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__value__writeHashtable\b/', $body),
                'discarded gettimeofday must not build hashtables'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testLiveClockGetterMatchZend(): void
    {
        // Minimal live set proven to match Zend under AOT. hrtime(true) can
        // surface as a non-scalar under thin NestedJIT on some hosts — covered
        // by discarded-only IR + DiscardedPureCallElision unit tests.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $ms = microtime(true);
        $gt = gettimeofday(true);
        echo is_float($ms) && $ms > 0.0 ? "1" : "0", "\n";
        echo is_float($gt) && $gt > 0.0 ? "1" : "0", "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_clock_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_clock_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $runOut = [];
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut, 'AOT must match Zend line-for-line');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
