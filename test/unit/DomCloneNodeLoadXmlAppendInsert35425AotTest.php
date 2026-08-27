<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: cloneNode on loadXML appendChild/insertBefore returns must use the moved
 * node's tag, not a stale child index / documentElement (#35425).
 *
 * php-src: ext/dom/node.c — dom_node_append_child / dom_node_insert_before /
 * php_dom_clone_node → xmlDocCopyNode.
 *
 * @group llvm
 */
final class DomCloneNodeLoadXmlAppendInsert35425AotTest extends TestCase
{
    public function testCloneNodeOnLoadXmlAppendChildReturn(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/aot_dom_clonenode_loadxml_appendchild_return.php'
        );
    }

    public function testCloneNodeOnLoadXmlInsertBeforeReturn(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/aot_dom_clonenode_loadxml_insertbefore_return.php'
        );
    }

    public function testCreateElementAppendChildStillMatches(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/aot_dom_clonenode_appendchild_return.php'
        );
    }

    public function testCreateElementInsertBeforeStillMatches(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/aot_dom_clonenode_insertbefore_return.php'
        );
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
        $bin = sys_get_temp_dir().'/dom_clonenode_35425_'.getmypid().'_'.md5($src);
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
