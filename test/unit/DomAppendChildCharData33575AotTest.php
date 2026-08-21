<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Element::appendChild(Comment/CDATA/PI/EntityRef) INNER_XML rebuild (#33575).
 *
 * @group llvm
 */
final class DomAppendChildCharData33575AotTest extends TestCase
{
    public function testAppendChildComment(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33575_dom_append_comment_aot.php',
            '<!--hi-->'
        );
    }

    public function testAppendChildCdata(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33575_dom_append_cdata_aot.php',
            '<![CDATA[hi]]>'
        );
    }

    public function testAppendChildPi(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33575_dom_append_pi_aot.php',
            '<?x y?>'
        );
    }

    public function testAppendChildEntityRef(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33575_dom_append_entity_aot.php',
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
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $bin = tempnam(sys_get_temp_dir(), 'phpc33575_');
        $this->assertNotFalse($bin);
        @unlink($bin);
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../../bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        exec($compile.' 2>&1', $cout, $ccode);
        $this->assertSame(0, $ccode, implode("\n", $cout));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $code);
        @unlink($bin);
        $this->assertSame(0, $code, implode("\n", $out));

        return implode("\n", $out);
    }
}
