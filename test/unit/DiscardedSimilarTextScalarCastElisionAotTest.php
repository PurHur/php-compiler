<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded similar_text (2-arg) / intval / floatval / boolval / strval on typed
 * args must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/string.c (similar_text), type.c / basic_functions.c
 *
 * @group aot-lint
 */
final class DiscardedSimilarTextScalarCastElisionAotTest extends TestCase
{
    public function testDiscardedOnlySimilarTextScalarCastsHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $a, string $b, int $n, float $f, bool $t, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                similar_text($a, $b);
                intval($n);
                intval($a, 10);
                floatval($f);
                doubleval($n);
                boolval($t);
                strval($a);
                strval($n);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('hello', 'hallo', 42, 1.5, true, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_stsc_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_stsc_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(phpc_similar_text|__phpc_similar_str|__phpc_similar_char|strtol|strtod|snprintf)\b/',
                    $body
                ),
                'discarded similar_text/intval/floatval/strval must be elided (no helper calls)'
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

    public function testLiveSimilarTextScalarCastsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $a, string $b, int $n, float $f): string {
            similar_text($a, $b);
            intval($n);
            floatval($f);
            boolval($n);
            strval($a);
            $sim = similar_text($a, $b);
            $i = intval($a, 10);
            $fv = floatval($n);
            $bv = boolval($a);
            $sv = strval($n);
            return $sim.'|'.$i.'|'.$fv.'|'.($bv ? '1' : '0').'|'.$sv;
        }
        echo work('hello', 'hallo', 42, 1.5), "\n";
        echo work('abc', 'abc', 7, 0.0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_stsc_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_stsc_live_'.getmypid().'.bin';
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
