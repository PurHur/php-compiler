<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: non-element Document children need full stand-in layout (#33556, peer #33546).
 *
 * @group llvm
 */
final class DomDocumentAppendNonElement33556AotTest extends TestCase
{
    public function testTextThenElement(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33556_dom_append_text_then_element_aot.php');
    }

    public function testCdataThenElement(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33556_dom_append_cdata_then_element_aot.php');
    }

    public function testPiThenElement(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33556_dom_append_pi_then_element_aot.php');
    }

    public function testEntityRefThenElement(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33556_dom_append_entity_ref_then_element_aot.php');
    }

    public function testTextOnlyDocumentElementNull(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33556_dom_append_text_only_aot.php');
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
        $bin = sys_get_temp_dir().'/dom_doc_ne_33556_'.getmypid().'_'.md5($src);
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
