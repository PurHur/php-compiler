<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded date / gmdate / mktime / gmmktime must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/date/php_date.c
 *
 * @group aot-lint
 */
final class DiscardedDateMktimeElisionAotTest extends TestCase
{
    public function testDiscardedOnlyDateMktimeHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, int $ts, int $h, int $m): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                date('Y-m-d');
                date('Y-m-d H:i:s', $ts);
                gmdate('c', $ts);
                mktime($h);
                mktime($h, $m, 0, 1, 1, 2024);
                gmmktime($h, $m);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, 1700000000, 12, 30), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_date_mktime_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_date_mktime_only_'.getmypid().'.bin';
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
                preg_match_all('/__compiler_format_datetime\b/', $body),
                'discarded date/gmdate must not call __compiler_format_datetime'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_mktime\b/', $body),
                'discarded mktime must not call __compiler_mktime'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_gmmktime\b/', $body),
                'discarded gmmktime must not call __compiler_gmmktime'
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

    public function testLiveDateMktimeMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $ts = 1700000000;
        echo date('Y-m-d', $ts), "\n";
        echo gmdate('Y-m-d', $ts), "\n";
        echo mktime(12, 0, 0, 1, 1, 2024), "\n";
        echo gmmktime(12, 0, 0, 1, 1, 2024), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_date_mktime_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_date_mktime_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $aot, 'live date/mktime must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
