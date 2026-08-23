<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: loadXML removeAttribute / removeAttributeNS update saveXML (#34257).
 *
 * @group llvm
 */
final class DomLoadXmlRemoveAttrNs34257AotTest extends TestCase
{
    public function testLoadXmlRemoveAttributeNsMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_34257_dom_loadxml_removeattrns_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('rm_b=<r xmlns:p="urn:x" p:a="1"/>', $aot);
        $this->assertStringContainsString('rm_ns=<r xmlns:p="urn:x"/>', $aot);
        $this->assertStringContainsString('has_a=0', $aot);
        $this->assertStringContainsString('has_b=0', $aot);
    }

    public function testSetAttributeNsThenRemoveMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_34257_dom_setattrns_then_remove_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('xmlns:p="urn:x"', $aot);
        $this->assertStringContainsString('b="2"', $aot);
        $this->assertStringNotContainsString('p:a=', $aot);
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
        $bin = sys_get_temp_dir().'/dom_rm_attrns_34257_'.getmypid().'_'.mt_rand();
        $cmd = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
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
