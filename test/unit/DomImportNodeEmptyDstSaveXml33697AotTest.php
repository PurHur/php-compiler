<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode into empty DOMDocument then document-wide saveXML (#33697).
 *
 * @group llvm
 * @group aot
 */
final class DomImportNodeEmptyDstSaveXml33697AotTest extends TestCase
{
    public function testImportNodeEmptyDstSaveXmlMatchZend(): void
    {
        $src = __DIR__.'/../repro/dom_importnode_empty_dst_savexml_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString("full=<?xml version=\"1.0\"?>\n<a id=\"1\"/>", $aot);
        $this->assertStringContainsString("src=<?xml version=\"1.0\"?>\n<r><a id=\"1\"><b/></a></r>", $aot);
        $this->assertStringNotContainsString("full=<?xml version=\"1.0\"?>\n<r>", $aot);
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
        $bin = sys_get_temp_dir().'/dom_import_empty_dst_savexml_33697_'.getmypid();
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
