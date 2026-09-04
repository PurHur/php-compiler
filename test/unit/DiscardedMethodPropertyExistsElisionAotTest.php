<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded method_exists / property_exists on typed objects must not lower (#36386).
 * Live results still match Zend. String class-name receivers stay live (autoload).
 *
 * php-src: Zend/zend_builtin_functions.c (method_exists, property_exists)
 *
 * @group aot-lint
 */
final class DiscardedMethodPropertyExistsElisionAotTest extends TestCase
{
    public function testDiscardedOnlyMethodPropertyExistsHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Node {
            public int $x = 0;
            public function bump(): void { $this->x++; }
        }
        function only_discarded(string $m, string $p, int $loops): int {
            // Object params / loop phis are TYPE_VALUE; recreate so the receiver
            // stays TYPE_OBJECT for discarded elision (#36386).
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                $o = new Node();
                method_exists($o, $m);
                property_exists($o, $p);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded('bump', 'x', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_mpe_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_mpe_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(__phpc_jit_method_exists|__phpc_jit_property_exists)/',
                    $body
                ),
                'discarded method_exists/property_exists must be elided (no helper calls)'
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

    public function testLiveMethodPropertyExistsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Node {
            public int $x = 0;
            public function bump(): void { $this->x++; }
        }
        function work(string $m, string $p): string {
            $o = new Node();
            method_exists($o, $m);
            property_exists($o, $p);
            $a = method_exists($o, 'bump') ? '1' : '0';
            $b = method_exists($o, 'nope') ? '1' : '0';
            $c = property_exists($o, 'x') ? '1' : '0';
            $d = property_exists($o, 'nope') ? '1' : '0';
            return $a.$b.$c.$d;
        }
        echo work('bump', 'x'), "\n";
        echo work('nope', 'nope'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_mpe_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_mpe_live_'.getmypid().'.bin';
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
