<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: cloneNode on replaceChild/removeChild DOMNode returns (#35386 / leftover #35377).
 *
 * php-src: ext/dom/node.c — dom_node_replace_child returns oldChild;
 * dom_node_remove_child returns the removed node; php_dom_clone_node.
 *
 * @group llvm
 */
final class DomCloneNodeReplaceRemoveChildReturn35386AotTest extends TestCase
{
    public function testCloneNodeOnReplaceChildReturn(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_clonenode_replacechild_return.php');
    }

    public function testCloneNodeOnRemoveChildReturn(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_clonenode_removechild_return.php');
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
        $bin = sys_get_temp_dir().'/dom_clonenode_rr_35386_'.getmypid().'_'.md5($src);
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
