<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DocumentFragment firstChild/lastChild after appendChild (#35461).
 *
 * php-src: ext/dom/node.c — dom_node_append_child / child edge accessors.
 *
 * @group llvm
 */
final class DomFragmentFirstChildAppend35461AotTest extends TestCase
{
    public function testFragmentFirstChildAfterAppendChildMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_fragment_firstchild_append.php');
    }

    public function testFragmentFirstChildAfterInsertBeforeNullMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_fragment_firstchild_insertbefore.php');
    }

    public function testFragmentFirstChildCloneNodeRepeated(): void
    {
        $src = __DIR__.'/../repro/aot_dom_fragment_firstchild_append.php';
        $zend = $this->runPhp($src);
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_frag_fc_rep_35461_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        for ($i = 0; $i < 5; ++$i) {
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "run $i: ".implode("\n", $out));
            $this->assertSame($zend, implode("\n", $out), "run $i output");
            $out = [];
        }
        @unlink($bin);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm, 'VM must match Zend');
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend');
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php')
            .' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_frag_fc_35461_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
