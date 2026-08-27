<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMXPath query()->item()->setIdAttribute uses tag-match attrs (#35447).
 *
 * php-src: ext/dom/xpath.c — query node-set; ext/dom/element.c — setIdAttribute
 *
 * @group llvm
 * @group aot
 */
final class DomXPathItemSetIdAttribute35447AotTest extends TestCase
{
    public function testXPathItemSetIdAttributeMatchesZend(): void
    {
        $src = __DIR__.'/../repro/aot_dom_xpath_item_setidattribute.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('tag=b', $aot);
        $this->assertStringContainsString('attr=y', $aot);
        $this->assertStringContainsString('y=b', $aot);
        $this->assertStringContainsString('x=null', $aot);
    }

    /** getElementsByTagName path from #35433 must stay green. */
    public function testGetElementsLaterSiblingStillMatchesZend(): void
    {
        $src = __DIR__.'/../repro/dom_setidattribute_getelementbyid_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('y=b', $aot);
        $this->assertStringContainsString('x=null', $aot);
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
        $bin = sys_get_temp_dir().'/dom_xpath_setid_35447_'.getmypid().'_'.mt_rand();
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
