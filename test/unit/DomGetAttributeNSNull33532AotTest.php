<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: getAttributeNS/hasAttributeNS(null, …) after setAttribute (#33532).
 *
 * @group llvm
 */
final class DomGetAttributeNSNull33532AotTest extends TestCase
{
    public function testNullNamespaceAfterSetAttribute(): void
    {
        $src = __DIR__.'/../repro/issue_33532_dom_getattrns_null_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('get=[v]', $aot);
        $this->assertStringContainsString('has=[yes]', $aot);
        $this->assertStringContainsString('nsget=[b]', $aot);
        $this->assertStringContainsString('nshas=[yes]', $aot);
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
        $bin = sys_get_temp_dir().'/dom_getattrns_33532_'.getmypid().'_'.md5($src);
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
