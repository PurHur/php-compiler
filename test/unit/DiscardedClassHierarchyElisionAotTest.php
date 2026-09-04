<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded class_parents / class_implements / class_uses on typed objects
 * must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/class.c (class_parents),
 * ext/standard/basic_functions.c (class_implements),
 * ext/standard/spl_functions.c (class_uses)
 *
 * @group aot-lint
 */
final class DiscardedClassHierarchyElisionAotTest extends TestCase
{
    public function testDiscardedOnlyClassHierarchyHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        interface I {}
        trait T {}
        class Base {}
        class Node extends Base implements I {
            use T;
        }
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                $o = new Node();
                class_parents($o);
                class_implements($o);
                class_uses($o);
                class_parents($o, false);
                class_implements($o, true);
                class_uses($o, false);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_ch_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_ch_only_'.getmypid().'.bin';
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
                preg_match_all('/__phpc_jit_class_parents/', $body),
                'discarded class_parents must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_jit_class_implements/', $body),
                'discarded class_implements must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_jit_class_uses/', $body),
                'discarded class_uses must not call helper'
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

    public function testLiveClassHierarchyMatchZend(): void
    {
        // Top-level only: AOT class_parents/implements/uses inside a user
        // function currently segfaults on master (pre-existing; not this slice).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        interface I {}
        trait T {}
        class Base {}
        class Node extends Base implements I {
            use T;
        }
        $o = new Node();
        class_parents($o);
        class_implements($o);
        class_uses($o);
        $p = class_parents($o);
        $i = class_implements($o);
        $u = class_uses($o);
        echo (isset($p['Base']) ? '1' : '0')
            . (isset($i['I']) ? '1' : '0')
            . (isset($u['T']) ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_ch_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_ch_live_'.getmypid().'.bin';
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
