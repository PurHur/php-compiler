<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded is_a / is_subclass_of on typed objects must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: Zend/zend_builtin_functions.c (is_a, is_subclass_of)
 *
 * @group aot-lint
 */
final class DiscardedIsAFamilyElisionAotTest extends TestCase
{
    public function testDiscardedOnlyIsAFamilyHasNoHelpers(): void
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
                is_a($o, 'Base');
                is_subclass_of($o, 'Base');
                is_a($o, 'Base', false);
                is_subclass_of($o, 'Base', true);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_isa_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_isa_only_'.getmypid().'.bin';
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
                preg_match_all('/__phpc_jit_is_a_string/', $body),
                'discarded is_a must not call string-subject helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_jit_is_subclass_of_string/', $body),
                'discarded is_subclass_of must not call string-subject helper'
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

    public function testLiveIsAFamilyMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        class Base {}
        class Node extends Base {}
        function work(): string {
            $o = new Node();
            is_a($o, 'Base');
            is_subclass_of($o, 'Base');
            is_a($o, 'Base', false);
            return (is_a($o, 'Base') ? '1' : '0')
                . (is_subclass_of($o, 'Base') ? '1' : '0')
                . (is_a($o, 'Node') ? '1' : '0')
                . (is_subclass_of($o, 'Node') ? '1' : '0');
        }
        echo work(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_isa_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_isa_live_'.getmypid().'.bin';
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
