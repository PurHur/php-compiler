<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded array_merge / array_replace / array_diff / array_intersect
 * (and key/assoc / recursive peers) must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/array.c
 *
 * @group aot-lint
 */
final class DiscardedArrayMergeDiffElisionAotTest extends TestCase
{
    public function testDiscardedOnlyArrayMergeDiffHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $a = [1, 2, 3];
            $b = [2, 4];
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                array_merge($a, $b);
                array_merge_recursive($a, $b);
                array_replace($a, $b);
                array_replace_recursive($a, $b);
                array_diff($a, $b);
                array_intersect($a, $b);
                array_diff_key($a, $b);
                array_intersect_key($a, $b);
                array_diff_assoc($a, $b);
                array_intersect_assoc($a, $b);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_arrmerge_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_arrmerge_only_'.getmypid().'.bin';
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
                preg_match_all('/__array_merge__(?:single|two)\b/', $body),
                'discarded array_merge must not call merge ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_merge_recursive__(?:single|two)\b/', $body),
                'discarded array_merge_recursive must not call recursive merge ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_replace__(?:single|two)\b/', $body),
                'discarded array_replace must not call replace ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_diff__(?:copy|filter)\b/', $body),
                'discarded array_diff must not call diff ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_intersect__(?:copy|filter)\b/', $body),
                'discarded array_intersect must not call intersect ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_diff_key__(?:copy|filter)\b/', $body),
                'discarded array_diff_key must not call diff_key ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_intersect_key__(?:copy|filter)\b/', $body),
                'discarded array_intersect_key must not call intersect_key ABI'
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

    public function testLiveArrayMergeDiffMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $a = [1, 2, 3];
        $b = [2, 4];
        echo implode(',', array_merge($a, $b)), "\n";
        echo implode(',', array_diff($a, $b)), "\n";
        echo implode(',', array_intersect($a, $b)), "\n";
        echo implode(',', array_replace($a, $b)), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrmerge_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrmerge_live_'.getmypid().'.bin';
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

    public function testSoftNullStayLive(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $a = [1, 2];
        $n = null;
        try {
            array_merge($a, $n);
        } catch (TypeError $e) {
            echo "merge\n";
        }
        try {
            array_diff($a, $n);
        } catch (TypeError $e) {
            echo "diff\n";
        }
        PHP;
        $path = sys_get_temp_dir().'/phpc_arrmerge_null_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_arrmerge_null_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zend, $zendRc);
            $this->assertSame($zend, $runOut, 'soft-null TypeError must stay live vs Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
