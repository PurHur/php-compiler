<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded array_key_first / array_key_last / array_is_list must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/array.c
 *
 * @group aot-lint
 */
final class DiscardedArrayKeyEdgeElisionAotTest extends TestCase
{
    public function testDiscardedOnlyArrayKeyEdgeHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $a = [1, 2, 3];
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                array_key_first($a);
                array_key_last($a);
                array_is_list($a);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_arrkey_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_arrkey_only_'.getmypid().'.bin';
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
                preg_match_all('/\barray_key_first_(?:empty|work|done|packed|string|fail|str_)/', $body),
                'discarded array_key_first must not lower key-walk blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/\barray_key_last_(?:walk|done|empty|str_)/', $body),
                'discarded array_key_last must not lower walk blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_is_list__check\b|ArrayIsListJitHelper/', $body),
                'discarded array_is_list must not call is-list helper'
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

    public function testLiveArrayKeyEdgeMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $a = ['x' => 1, 'y' => 2];
        $b = [10, 20, 30];
        echo (string) array_key_first($a), "\n";
        echo (string) array_key_last($a), "\n";
        echo array_is_list($a) ? "1" : "0", "\n";
        echo array_is_list($b) ? "1" : "0", "\n";
        echo (string) array_key_first($b), "\n";
        echo (string) array_key_last($b), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrkey_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrkey_live_'.getmypid().'.bin';
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
            $this->assertSame(0, $runRc, implode("\n", $runOut));
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
