<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: DOMAttr::$textContent read/write matches Zend value/nodeValue (#33864).
 *
 * php-src: ext/dom/node.c — attribute textContent uses Attr value.
 *
 * @group llvm
 * @group aot
 */
final class DomAttrTextContent33864AotTest extends TestCase
{
    public function testAttrTextContentMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_33864_dom_attr_textcontent_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString("string(1) \"v\"", $aot);
        $this->assertStringContainsString("string(1) \"w\"", $aot);
        $this->assertStringContainsString("done", $aot);
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
        $bin = sys_get_temp_dir().'/dom_attr_textcontent_33864_'.getmypid();
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
