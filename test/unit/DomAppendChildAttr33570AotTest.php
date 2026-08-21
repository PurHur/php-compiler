<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Element::appendChild(Attr) + setAttributeNode must match Zend saveXML (#33570).
 *
 * @group llvm
 */
final class DomAppendChildAttr33570AotTest extends TestCase
{
    public function testAppendChildAttrMatchesZendSaveXml(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33570_dom_append_attr_aot.php');
    }

    public function testSetAttributeNodeMatchesZendSaveXml(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33570_dom_setattrnode_aot.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('id="x"', $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'phpc33570_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../../bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $cout, $ccode);
        $this->assertSame(0, $ccode, implode("\n", $cout));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $code);
        @unlink($bin);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out);
    }
}
