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
        $zend = $this->runPhp($src);
        $this->assertStringContainsString('id_chain=true', $zend);
        $this->assertStringContainsString('class_chain=false', $zend);
        $this->assertStringContainsString('id_assigned=true', $zend);
        $this->assertStringContainsString('dtd_chain=true', $zend);
        $this->assertStringContainsString('dtd_assigned=true', $zend);
    }

    private function runPhp(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
