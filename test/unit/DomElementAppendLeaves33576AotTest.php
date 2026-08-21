<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Element::appendChild of CDATA/comment/PI/entity-ref (#33576).
 *
 * @group llvm
 */
final class DomElementAppendLeaves33576AotTest extends TestCase
{
    public function testCdata(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33576_dom_element_append_cdata_aot.php',
            '<![CDATA[x<y]]>'
        );
    }

    public function testComment(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33576_dom_element_append_comment_aot.php',
            '<!--hi-->'
        );
    }

    public function testPi(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33576_dom_element_append_pi_aot.php',
            '<?t d?>'
        );
    }

    public function testEntityRef(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33576_dom_element_append_eref_aot.php',
            '&amp;'
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
        $bin = sys_get_temp_dir().'/dom_leaves_33576_'.getmypid().'_'.md5($src);
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
