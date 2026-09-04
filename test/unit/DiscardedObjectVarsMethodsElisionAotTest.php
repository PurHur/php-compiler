<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded get_object_vars / get_mangled_object_vars / get_class_methods on
 * typed objects must not lower (#36386). Live results still match Zend.
 *
 * php-src: Zend/zend_builtin_functions.c (get_object_vars, get_class_methods),
 * ext/standard/var.c (get_mangled_object_vars)
 *
 * @group aot-lint
 */
final class DiscardedObjectVarsMethodsElisionAotTest extends TestCase
{
    public function testDiscardedOnlyObjectVarsMethodsHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Node {
            public int $n = 1;
            private int $hidden = 2;
            public function m(): void {}
        }
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                $o = new Node();
                get_object_vars($o);
                get_mangled_object_vars($o);
                get_class_methods($o);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_ovm_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_ovm_only_'.getmypid().'.bin';
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
                preg_match_all('/__phpc_jit_get_object_vars/', $body),
                'discarded get_object_vars / get_mangled_object_vars must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_jit_get_class_methods/', $body),
                'discarded get_class_methods must not call helper'
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

    public function testLiveObjectVarsMethodsMatchZend(): void
    {
        // Top-level only. Skip get_class_methods live AOT — returns NULL on
        // master today (pre-existing; discarded path is covered by IR assert).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Node {
            public int $n = 7;
            private int $hidden = 9;
        }
        $o = new Node();
        get_object_vars($o);
        get_mangled_object_vars($o);
        $v = get_object_vars($o);
        $m = get_mangled_object_vars($o);
        echo (isset($v['n']) && 7 === $v['n'] ? '1' : '0')
            . (isset($m["\0Node\0hidden"]) && 9 === $m["\0Node\0hidden"] ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_ovm_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_ovm_live_'.getmypid().'.bin';
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
