<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded defined / array_key_exists / key_exists on typed args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/basic_functions.c (defined), ext/standard/array.c (array_key_exists)
 *
 * @group aot-lint
 */
final class DiscardedDefinedArrayKeyExistsElisionAotTest extends TestCase
{
    public function testDiscardedOnlyDefinedAndArrayKeyExistsHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $c, string $k, int $loops): int {
            $csum = 0;
            for ($i = 0; $i < $loops; ++$i) {
                $a = ['k' => 1, 2 => 3];
                defined($c);
                array_key_exists($k, $a);
                key_exists(2, $a);
                $csum += $i;
            }
            return $csum;
        }
        echo only_discarded('PHP_VERSION', 'k', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_dake_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_dake_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(__hashtable__offsetIsSetStringKey|__hashtable__peekStringKeyValue)/',
                    $body
                ),
                'discarded defined/array_key_exists must be elided (no helper calls)'
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

    public function testLiveDefinedAndArrayKeyExistsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $c, string $k): string {
            $a = ['k' => 1, 2 => 3];
            defined($c);
            array_key_exists($k, $a);
            key_exists(2, $a);
            $d = defined('PHP_VERSION') ? '1' : '0';
            $e = defined('NO_SUCH_CONST_36386') ? '1' : '0';
            $f = array_key_exists('k', $a) ? '1' : '0';
            $g = array_key_exists('missing', $a) ? '1' : '0';
            $h = key_exists(2, $a) ? '1' : '0';
            return $d.$e.$f.$g.$h;
        }
        echo work('PHP_VERSION', 'k'), "\n";
        echo work('NO_SUCH_CONST_36386', 'missing'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_dake_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_dake_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
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
