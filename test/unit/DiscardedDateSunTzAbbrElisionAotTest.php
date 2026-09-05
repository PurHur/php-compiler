<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded date_sun_info / timezone_name_from_abbr must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/date/php_date.c
 *
 * @group aot-lint
 */
final class DiscardedDateSunTzAbbrElisionAotTest extends TestCase
{
    public function testDiscardedOnlyDateSunTzAbbrHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, int $ts, float $lat, float $lon, string $abbr): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                date_sun_info($ts, $lat, $lon);
                date_sun_info(1700000000, 51.5, -0.1);
                timezone_name_from_abbr($abbr);
                timezone_name_from_abbr($abbr, 3600);
                timezone_name_from_abbr('CET', 3600, 0);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, 1700000000, 51.5, -0.1, 'CET'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_sun_tz_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_sun_tz_only_'.getmypid().'.bin';
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
                preg_match_all('/__value__writeHashtable\b/', $body),
                'discarded date_sun_info must not materialize hashtables'
            );
            $this->assertSame(
                0,
                preg_match_all('/timezone_name_from_abbr|TimezoneNameFromAbbr/i', $body),
                'discarded timezone_name_from_abbr must not lower a helper call'
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

    public function testLiveDateSunTzAbbrMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $info = date_sun_info(1700000000, 51.5, -0.1);
        echo isset($info['sunrise']) ? 'ok' : 'missing', "\n";
        echo timezone_name_from_abbr('CET'), "\n";
        echo timezone_name_from_abbr('CET', 3600, 0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_sun_tz_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_sun_tz_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $aot = [];
            exec(escapeshellarg($bin), $aot, $aotRc);
            $this->assertSame(0, $aotRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $aot, 'live date_sun_info/timezone_name_from_abbr must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
