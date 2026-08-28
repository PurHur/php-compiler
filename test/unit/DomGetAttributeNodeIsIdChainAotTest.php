<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * VM/AOT: chained getAttributeNode()->isId() after setIdAttribute (re-#25841).
 *
 * @group llvm
 */
final class DomGetAttributeNodeIsIdChainAotTest extends TestCase
{
    public function testChainedIsIdAfterSetIdAttributeMatchesZendOnVm(): void
    {
        $src = __DIR__.'/../repro/maintainer_gap_dom_getattributenode_isid_chain_after_setid.php';
        $zend = $this->runVm($src);
        $this->assertStringContainsString('id_chain=true', $zend);
        $this->assertStringContainsString('class_chain=false', $zend);
        $this->assertStringContainsString('id_assigned=true', $zend);
        $this->assertStringContainsString('dtd_chain=true', $zend);
        $this->assertStringContainsString('dtd_assigned=true', $zend);
    }

    public function testIsIdAfterTryCatchMatchesZendOnAot(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/aot_dom_isid_after_try_single.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runVm(string $src): string
    {
        return $this->runPhp($this->vmBin().' '.escapeshellarg($src));
    }

    private function runPhp(string $cmd): string
    {
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function vmBin(): string
    {
        $root = dirname(__DIR__, 2);

        return escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php');
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_isid_try_'.getmypid().'_'.md5($src);
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
