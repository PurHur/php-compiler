<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded in_array / array_search / array_pad / array_fill /
 * array_fill_keys / array_column must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/array.c
 *
 * @group aot-lint
 */
final class DiscardedArrayLookupConstructElisionAotTest extends TestCase
{
    public function testDiscardedOnlyArrayLookupConstructHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $a = [1, 2, 3];
            $rows = [['name' => 'a', 'id' => 1], ['name' => 'b', 'id' => 2]];
            $keys = ['x', 'y'];
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                in_array(2, $a);
                in_array(2, $a, true);
                array_search(2, $a);
                array_search(2, $a, false);
                array_pad($a, 5, 0);
                array_fill(0, 3, 9);
                array_fill_keys($keys, 1);
                array_column($rows, 'name');
                array_column($rows, 'name', 'id');
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_arrlookup_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_arrlookup_only_'.getmypid().'.bin';
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
                preg_match_all('/\bin_array_llvm_/', $body),
                'discarded in_array must not lower in_array_llvm blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/\barray_search_llvm_/', $body),
                'discarded array_search must not lower array_search_llvm blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_pad__copy(?:_typed)?\b/', $body),
                'discarded array_pad must not call pad ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_fill__copy\b/', $body),
                'discarded array_fill must not call fill ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_fill_keys__copy\b/', $body),
                'discarded array_fill_keys must not call fill_keys ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_column__/', $body),
                'discarded array_column must not call column ABI'
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

    public function testLiveArrayLookupConstructMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $a = [1, 2, 3];
        $rows = [['name' => 'a', 'id' => 1], ['name' => 'b', 'id' => 2]];
        $keys = ['x', 'y'];
        echo in_array(2, $a) ? '1' : '0', "\n";
        echo array_search(3, $a), "\n";
        echo implode(',', array_pad($a, 5, 0)), "\n";
        echo implode(',', array_fill(0, 3, 9)), "\n";
        echo implode(',', array_fill_keys($keys, 1)), "\n";
        echo implode(',', array_column($rows, 'name')), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrlookup_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrlookup_live_'.getmypid().'.bin';
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

    public function testSoftNullHaystackStaysLiveMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        $a = null;
        try {
            in_array(1, $a);
            echo "no\n";
        } catch (TypeError $e) {
            echo "te\n";
        }
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrlookup_null_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrlookup_null_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zend, $zendRc);
            $this->assertSame($zendRc, $runRc);
            $this->assertSame($zend, $runOut, 'AOT must match Zend on soft-null haystack');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
