<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded getdate / localtime / idate / getrandmax / mt_getrandmax must not
 * lower (#36386). Live results still match Zend on shape checks.
 *
 * php-src: ext/date/php_date.c, ext/standard/datetime.c, ext/random/random.c
 *
 * @group aot-lint
 */
final class DiscardedCivilDateRandmaxElisionAotTest extends TestCase
{
    public function testDiscardedOnlyCivilDateAndRandmaxHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, int $ts): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                getdate();
                getdate($ts);
                localtime();
                localtime($ts);
                localtime($ts, true);
                idate('Y');
                idate('Y', $ts);
                getrandmax();
                mt_getrandmax();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, 1700000000), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_civil_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_civil_only_'.getmypid().'.bin';
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
                preg_match_all('/\btm_sec\b|\btm_year\b|\bweekday\b/', $body),
                'discarded getdate/localtime must not materialize civil keys'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_default_tz_is_dst\b/', $body),
                'discarded localtime must not call tz helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/idate_(bad_len|ok_len|merge|tok_)/', $body),
                'discarded idate must not lower civil select blocks'
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

    public function testLiveCivilDateAndRandmaxMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $ts = 1700000000;
        $gd = getdate($ts);
        $lt = localtime($ts, true);
        echo isset($gd['year'], $gd['mon'], $gd['mday']) ? "1" : "0", "\n";
        echo isset($lt['tm_year'], $lt['tm_mon'], $lt['tm_mday']) ? "1" : "0", "\n";
        echo idate('Y', $ts), "\n";
        echo getrandmax() === mt_getrandmax() ? "1" : "0", "\n";
        echo getrandmax() > 0 ? "1" : "0", "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_civil_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_civil_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
