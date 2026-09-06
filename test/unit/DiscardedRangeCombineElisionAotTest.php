<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded range / array_combine on proven-safe args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(range) / PHP_FUNCTION(array_combine)
 *
 * @group aot-lint
 */
final class DiscardedRangeCombineElisionAotTest extends TestCase
{
    public function testDiscardedOnlyRangeCombineHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                range(1, 5);
                range(5, 1);
                range(0, 8, 2);
                array_combine(['a', 'b'], ['x', 'y']);
                array_combine(['k1', 'k2', 'k3'], ['v1', 'v2', 'v3']);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_range_combine_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_range_combine_only_'.getmypid().'.bin';
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
                preg_match_all('/__range_(?:int|char|float)__copy\b/', $body),
                'discarded range must not call RangeIntRuntime ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/\brange_step_(?:ok|err)_/', $body),
                'discarded range must not emit step ValueError guards'
            );
            $this->assertSame(
                0,
                preg_match_all('/__array_combine__copy\b/', $body),
                'discarded array_combine must not call combine ABI'
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

    public function testLiveRangeCombineStillMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_range_combine(): string {
            $r = range(1, 3);
            $c = array_combine(['a', 'b'], [1, 2]);
            return implode(',', $r).'|'.implode(',', $c).'|'.implode(',', array_keys($c));
        }
        echo live_range_combine(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_range_combine_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_range_combine_live_'.getmypid().'.bin';
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
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testLiveUnsafeRangeCombineStaysLowered(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_unsafe(int $n, array $keys, array $vals): int {
            range(0, 4, $n);
            array_combine($keys, $vals);
            return $n;
        }
        echo live_unsafe(2, ['a'], [1]), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_range_combine_unsafe_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_range_combine_unsafe_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@live_unsafe\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @live_unsafe');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertGreaterThan(
                0,
                preg_match_all('/__range_(?:int|char|float)__copy\b|\brange_step_/', $body),
                'runtime-step range must stay lowered'
            );
            $this->assertGreaterThan(
                0,
                preg_match_all('/__array_combine__copy\b/', $body),
                'runtime array_combine must stay lowered'
            );
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
