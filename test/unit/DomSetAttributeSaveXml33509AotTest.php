<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttribute must appear in saveXML (#33509).
 *
 * @group llvm
 */
final class DomSetAttributeSaveXml33509AotTest extends TestCase
{
    public function testAppendAfterSetAttribute(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33509_dom_setattr_savexml_aot.php');
    }

    public function testSetAttributeAfterAppend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33509_dom_setattr_after_append_aot.php');
    }

    public function testNodeScopedSaveXml(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33509_dom_setattr_savexml_self_aot.php');
    }

    public function testDocumentFragmentExpand(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33509_dom_fragment_setattr_savexml_aot.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('k="v"', $aot);
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
        $bin = sys_get_temp_dir().'/dom_setattr_33509_'.getmypid().'_'.md5($src);
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
