<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML getAttributeNode Attr textContent/nodeValue matches Zend (#33904).
 *
 * php-src: ext/dom/node.c — attribute textContent uses Attr value (peer #33864).
 *
 * @group llvm
 * @group aot
 */
final class DomAttrLoadXmlTextContent33904AotTest extends TestCase
{
    public function testLoadXmlAttrTextContentMatchesZend(): void
    {
        $src = __DIR__.'/../repro/dom_attr_loadxml_textcontent_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString("string(1) \"v\"", $aot);
        $this->assertStringContainsString("string(1) \"w\"", $aot);
        $this->assertStringContainsString('done', $aot);
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
        $bin = sys_get_temp_dir().'/dom_attr_loadxml_tc_33904_'.getmypid();
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
