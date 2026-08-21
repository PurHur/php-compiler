<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: getAttributeNodeNS(null, …) must see Attr (#33534).
 *
 * @group llvm
 */
final class DomGetAttributeNodeNSNull33534AotTest extends TestCase
{
    public function testAfterSetAttribute(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33534_dom_getattrnodens_null_aot.php');
    }

    public function testAfterSetAttributeNSNull(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33534_dom_setattrns_null_getattrnodens_aot.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('class=DOMAttr', $aot);
        $this->assertStringContainsString('value=v', $aot);
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
        $bin = sys_get_temp_dir().'/dom_getattrnodens_33534_'.getmypid().'_'.md5($src);
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
