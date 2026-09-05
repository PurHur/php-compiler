<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded array_keys / array_values / array_reverse /
 * array_change_key_case must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/array.c
 *
 * @group aot-lint
 */
final class DiscardedArrayCopyElisionAotTest extends TestCase
{
    public function testDiscardedOnlyArrayCopyHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $a = [1, 2, 3];
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                array_keys($a);
                array_values($a);
                array_reverse($a);
                array_reverse($a, true);
                array_change_key_case($a);
                array_change_key_case($a, CASE_UPPER);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_arrcopy_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_arrcopy_only_'.getmypid().'.bin';
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
                preg_match_all('/__array_keys__copy\b|__array_keys__matching\b/', $body),
                'discarded array_keys must not call keys copy ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_values__copy_direct\b/', $body),
                'discarded array_values must not call values copy ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_reverse__copy\b/', $body),
                'discarded array_reverse must not call reverse ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_change_key_case__llvm\b/', $body),
                'discarded array_change_key_case must not call case ABI'
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

    public function testLiveArrayCopyMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $a = ['x' => 1, 'Y' => 2];
        $b = [10, 20, 30];
        echo implode(',', array_keys($a)), "\n";
        echo implode(',', array_values($a)), "\n";
        echo implode(',', array_values(array_reverse($b))), "\n";
        echo implode(',', array_keys(array_change_key_case($a, CASE_UPPER))), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrcopy_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrcopy_live_'.getmypid().'.bin';
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
