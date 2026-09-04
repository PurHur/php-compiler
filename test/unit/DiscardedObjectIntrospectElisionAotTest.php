<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded get_class / get_parent_class / spl_object_id / spl_object_hash on
 * typed objects must not lower (#36386). Live results still match Zend.
 *
 * php-src: Zend/zend_builtin_functions.c (get_class, get_parent_class),
 * ext/spl/php_spl.c (spl_object_id, spl_object_hash)
 *
 * @group aot-lint
 */
final class DiscardedObjectIntrospectElisionAotTest extends TestCase
{
    public function testDiscardedOnlyObjectIntrospectHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Base {}
        class Node extends Base {}
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                $o = new Node();
                get_class($o);
                get_parent_class($o);
                spl_object_id($o);
                spl_object_hash($o);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_oi_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_oi_only_'.getmypid().'.bin';
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
                preg_match_all('/dom_gc_stand/', $body),
                'discarded get_class must not emit DOM stand-in get_class blocks'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_jit_get_parent_class/', $body),
                'discarded get_parent_class must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_object_handle_baseline/', $body),
                'discarded spl_object_id/hash must not load handle baseline'
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

    public function testLiveObjectIntrospectMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Base {}
        class Node extends Base {}
        function work(): string {
            $o = new Node();
            // Discarded (elided when typed object).
            get_class($o);
            get_parent_class($o);
            spl_object_id($o);
            spl_object_hash($o);
            // Live get_class — AOT get_parent_class(object) still needs VM helper (#36386 slice).
            return get_class($o);
        }
        echo work(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_oi_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_oi_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'live get_class must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}