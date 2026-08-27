<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: getElementsByTagName on importNode destination must not steal source loadXML (#35405 / re-#34630).
 *
 * php-src: ext/dom/nodelist.c — live NodeList length / item
 * php-src: ext/dom/document.c — DOMDocument::importNode / getElementsByTagName
 *
 * @group llvm
 */
final class DomImportGetElementsLength35405AotTest extends TestCase
{
    public function testImportNodeDestinationGetElementsByTagNameLength(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_35405_import_getelements_length.php');
    }

    public function testPrefixedLoadXmlGetElementsStillCounts(): void
    {
        // Guard #34936: dropping lastCompileTimeXml steal must not zero prefixed local-name matches.
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34936_getelementsbytagname_ns_aot.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend for '.basename($src));
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
        $bin = sys_get_temp_dir().'/dom_import_getelements_35405_'.getmypid().'_'.md5($src);
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
