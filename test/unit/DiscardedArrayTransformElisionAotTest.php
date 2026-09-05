<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded array_unique / array_slice / array_chunk / array_sum /
 * array_product must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/array.c
 *
 * @group aot-lint
 */
final class DiscardedArrayTransformElisionAotTest extends TestCase
{
    public function testDiscardedOnlyArrayTransformHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $a = [1, 2, 2, 3];
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                array_unique($a);
                array_unique($a, SORT_REGULAR);
                array_slice($a, 1);
                array_slice($a, 1, 2);
                array_slice($a, 0, 2, true);
                array_chunk($a, 2);
                array_chunk($a, 2, true);
                array_sum($a);
                array_product($a);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_arrxform_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_arrxform_only_'.getmypid().'.bin';
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
                preg_match_all('/\barray_unique_(?:pk|sk|reg_pk|reg_sk|eq_pk|eq_sk)_/', $body),
                'discarded array_unique must not lower unique walk blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/\bht_slice_(?:head|body|copy|done)_|__array_slice__copy\b/', $body),
                'discarded array_slice must not lower slice blocks / ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_chunk__copy\b/', $body),
                'discarded array_chunk must not call chunk ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/\barray_sum_llvm_/', $body),
                'discarded array_sum must not lower sum fold blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/\barray_product_llvm_/', $body),
                'discarded array_product must not lower product fold blocks'
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

    public function testLiveArrayTransformMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $a = [1, 2, 2, 3];
        echo implode(',', array_unique($a)), "\n";
        echo implode(',', array_slice($a, 1, 2)), "\n";
        echo (string) array_sum($a), "\n";
        echo (string) array_product($a), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrxform_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrxform_live_'.getmypid().'.bin';
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

    public function testSoftNullAndRuntimeChunkSizeStayLive(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function stay_live(?array $a, int $n): int {
            array_sum($a);
            array_chunk([1, 2, 3], $n);
            return 1;
        }
        echo stay_live([1, 2], 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrxform_live_err_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrxform_live_err_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@stay_live\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @stay_live');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);
            $this->assertMatchesRegularExpression(
                '/array_sum_llvm_/',
                $body,
                'soft-null array_sum must stay lowered'
            );
            $this->assertMatchesRegularExpression(
                '/__array_chunk__copy\b/',
                $body,
                'non-constant array_chunk size must stay lowered'
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
