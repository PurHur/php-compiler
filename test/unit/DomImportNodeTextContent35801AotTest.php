<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: deep importNode must seed Element textContent/nodeValue from InnerXml
 * (php-src xmlDocCopyNode + textContent aggregate; #35801).
 *
 * @group llvm
 */
final class DomImportNodeTextContent35801AotTest extends TestCase
{
    public function testDeepImportTextContentMatchZend(): void
    {
        $src = __DIR__.'/../repro/aot_dom_importnode_textcontent.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('textContent=text', $aot);
        $this->assertStringContainsString('nested=hithere', $aot);
    }

    public function testMaintainerGapImportNodeMatchZend(): void
    {
        $src = __DIR__.'/../repro/maintainer_gap_dom_import_node.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame("x:text\nok", $aot);
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
        $bin = sys_get_temp_dir().'/dom_import_tc_35801_'.getmypid().'_'.mt_rand(0, 99999);
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
