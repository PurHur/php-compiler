<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded checkdate / hash_equals on typed args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/datetime.c (checkdate), ext/hash/hash.c (hash_equals)
 *
 * @group aot-lint
 */
final class DiscardedCheckdateHashEqualsElisionAotTest extends TestCase
{
    public function testDiscardedOnlyCheckdateHashEqualsHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $m, int $d, int $y, string $k, string $u, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                checkdate($m, $d, $y);
                hash_equals($k, $u);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(2, 29, 2024, 'a', 'b', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_cdhe_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_cdhe_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(__compiler_checkdate|__compiler_hash_equals)\b/',
                    $body
                ),
                'discarded checkdate/hash_equals must be elided (no helper calls)'
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

    public function testLiveCheckdateHashEqualsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $m, int $d, int $y, string $k, string $u): string {
            checkdate($m, $d, $y);
            hash_equals($k, $u);
            $ok = checkdate(2, 29, 2024) ? '1' : '0';
            $bad = checkdate(2, 30, 2024) ? '1' : '0';
            $eq = hash_equals('secret', 'secret') ? '1' : '0';
            $ne = hash_equals('secret', 'other') ? '1' : '0';
            return $ok.'|'.$bad.'|'.$eq.'|'.$ne;
        }
        echo work(2, 29, 2024, 'a', 'b'), "\n";
        echo work(1, 1, 2000, 'x', 'x'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_cdhe_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_cdhe_live_'.getmypid().'.bin';
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
