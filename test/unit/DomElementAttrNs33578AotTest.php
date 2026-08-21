<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: createAttributeNS + appendChild / setAttributeNodeNS keep xmlns in saveXML (#33578).
 *
 * @group llvm
 */
final class DomElementAttrNs33578AotTest extends TestCase
{
    public function testAppendChildAttrNs(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33578_dom_append_attr_ns_aot.php');
    }

    public function testSetAttributeNodeNs(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33578_dom_setattrnode_ns_aot.php');
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
        $bin = sys_get_temp_dir().'/dom_el_attr_ns_33578_'.getmypid().'_'.md5($src);
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
