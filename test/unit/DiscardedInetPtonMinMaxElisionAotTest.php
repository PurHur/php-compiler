<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded inet_pton / inet_ntop / min / max on typed args must not lower
 * (#36386). Live results still match Zend. (fmin/fmax covered in unit elision
 * tests — profile-gated phantoms.)
 *
 * php-src: ext/standard/basic_functions.c (inet_pton/inet_ntop), array.c (min/max)
 *
 * @group aot-lint
 */
final class DiscardedInetPtonMinMaxElisionAotTest extends TestCase
{
    public function testDiscardedOnlyInetPtonMinMaxHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $ip, string $bin, int $a, int $b, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                inet_pton($ip);
                inet_ntop($bin);
                min($a, $b);
                max($a, $b);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('127.0.0.1', "\x7f\x00\x00\x01", 3, 7, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_inetmm_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_inetmm_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(__compiler_inet_pton|__compiler_inet_ntop)\b/',
                    $body
                ),
                'discarded inet_pton/inet_ntop must be elided (no helper calls)'
            );
            // Typed min/max lower to icmp/select when live; discarded must not.
            // Loop compare ($k < $loops) still uses icmp — count icmp from min/max
            // via select of the two typed ints (slt + select pattern beyond the loop).
            $this->assertSame(
                0,
                preg_match_all('/select i64 %/', $body),
                'discarded min/max must not emit native long select'
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

    public function testLiveInetPtonMinMaxMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $ip, int $a, int $b): string {
            // Discarded typed forms (elision under test).
            inet_pton($ip);
            min($a, $b);
            max($a, $b);
            // Live results.
            $p = inet_pton('127.0.0.1');
            $n = inet_ntop($p);
            $mi = min(3, 7);
            $ma = max(3, 7);
            return bin2hex((string) $p).'|'.$n.'|'.$mi.'|'.$ma;
        }
        echo work('127.0.0.1', 3, 7), "\n";
        echo work('0.0.0.0', 9, 1), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_inetmm_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_inetmm_live_'.getmypid().'.bin';
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
