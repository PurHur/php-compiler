<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Element appendChild(Attr) / setAttributeNode keep attrs in saveXML (#33570).
 *
 * @group llvm
 */
final class DomElementAppendAttr33570AotTest extends TestCase
{
    public function testAppendChildAttr(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33570_dom_append_attr_aot.php');
    }

    public function testSetAttributeNodeAttr(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33570_dom_setattrnode_attr_aot.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_el_attr_33570_'.getmypid().'_'.md5($src);
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
