<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded levenshtein / str_getcsv (4-arg) / number_format on typed args
 * must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/levenshtein.c, file.c (str_getcsv), number_format.c
 *
 * @group aot-lint
 */
final class DiscardedLevenshteinCsvNumberFormatElisionAotTest extends TestCase
{
    public function testDiscardedOnlyLevenshteinCsvNumberFormatHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $a, string $b, string $line, string $sep, string $enc, string $esc, float $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                levenshtein($a, $b);
                levenshtein($a, $b, 1, 1, 1);
                str_getcsv($line, $sep, $enc, $esc);
                number_format($n);
                number_format($n, 2, '.', ',');
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('kitten', 'sitting', 'a,b,c', ',', '"', '\\', 1234.5, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_lcn_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_lcn_only_'.getmypid().'.bin';
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
                preg_match_all(
                    '/phpc_levenshtein|__compiler_str_getcsv|__compiler_number_format|StringLevenshtein|JitStrGetcsv|JitNumberFormat|levenshtein_bridge/',
                    $body
                ),
                'discarded levenshtein/str_getcsv/number_format must be elided'
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

    public function testLiveLevenshteinCsvNumberFormatMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $a, string $b, string $line, float $n): string {
            levenshtein($a, $b);
            str_getcsv($line, ',', '"', '\\');
            number_format($n, 2);
            $d = levenshtein($a, $b);
            $row = str_getcsv($line, ',', '"', '\\');
            $fmt = number_format($n, 2, '.', ',');
            return $d.'|'.implode(';', $row).'|'.$fmt;
        }
        echo work('kitten', 'sitting', 'x,y', 1234.5), "\n";
        echo work('abc', 'abc', '1,2,3', 7.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_lcn_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_lcn_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut, 'live results must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
