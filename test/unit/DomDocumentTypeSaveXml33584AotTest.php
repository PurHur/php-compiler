<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DocumentType in saveXML + insertBefore before documentElement (#33584).
 *
 * @see php-src ext/dom/document.c / node.c dom_node_insert_before
 *
 * @group llvm
 */
final class DomDocumentTypeSaveXml33584AotTest extends TestCase
{
    public function testAppendDoctypeSaveXml(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33584_dom_doctype_append_savexml_aot.php');
    }

    public function testInsertBeforeDoctypeSaveXml(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33584_dom_doctype_insertbefore_savexml_aot.php');
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
        $bin = sys_get_temp_dir().'/dom_doc_dt_33584_'.getmypid().'_'.md5($src);
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
