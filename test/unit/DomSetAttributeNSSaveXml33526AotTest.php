<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttributeNS must appear in saveXML (#33526 / peer #33509).
 *
 * @group llvm
 */
final class DomSetAttributeNSSaveXml33526AotTest extends TestCase
{
    public function testAppendAfterSetAttributeNS(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33526_dom_setattrns_savexml_aot.php',
            'x:k="v"'
        );
    }

    public function testSetAttributeNSAfterAppend(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33526_dom_setattrns_after_append_aot.php',
            'x:k="v"'
        );
    }

    public function testNullNamespace(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33526_dom_setattrns_null_ns_aot.php',
            'k="v"'
        );
    }

    private function assertAotMatchesZend(string $src, string $needle): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString($needle, $aot);
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
        $bin = sys_get_temp_dir().'/dom_setattrns_33526_'.getmypid().'_'.md5($src);
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
