<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded implode/join on typed array pieces must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/string.c php_implode
 *
 * @group aot-lint
 */
final class DiscardedImplodeJoinElisionAotTest extends TestCase
{
    public function testDiscardedOnlyImplodeJoinHasNoWalkBlocks(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $a = ['a', 'b', 'c'];
            $empty = [];
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                implode(',', $a);
                implode($a);
                join('-', $a);
                join($empty);
                implode(',', $empty);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_implode_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_implode_only_'.getmypid().'.bin';
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
                preg_match_all('/\bimplode_(?:pk|sk)_(?:head|body|take|next|done)_/', $body),
                'discarded implode/join must not lower packed/string-key walk blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/\bimplode_(?:first|rest|part_done)_/', $body),
                'discarded implode/join must not lower part append blocks'
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

    public function testLiveImplodeStillMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_implode(): string {
            $a = ['x', 'y', 'z'];
            return implode(':', $a).'|'.join('-', $a).'|'.implode($a);
        }
        echo live_implode(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_implode_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_implode_live_'.getmypid().'.bin';
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
}
