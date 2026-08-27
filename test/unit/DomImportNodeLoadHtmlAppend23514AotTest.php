<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT loadHTML html/body skeleton + importNode appendChild (#23514, re-#32996).
 *
 * Primary regression: appendChild onto getElementsByTagName('body')->item() after
 * loadHTML('<html><body></body></html>') SIGSEGV'd — body was a detached rematerialize.
 *
 * @group llvm
 */
final class DomImportNodeLoadHtmlAppend23514AotTest extends TestCase
{
    public function testImportNodeAppendToLoadHtmlBodyDoesNotSegfault(): void
    {
        $src = __DIR__.'/../repro/aot_dom_importnode_xml_to_loadhtml_append.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertStringContainsString('xml2html isId=false gebi=null', $zend);
        $this->assertStringContainsString('xml2html isId=false gebi=null', $aot);
        $this->assertStringContainsString('rewrite isId=false gebi=null', $zend);
        $this->assertStringContainsString('rewrite isId=false gebi=null', $aot);
        $this->assertStringContainsString('remove+set isId=true gebi=div', $zend);
        $this->assertStringContainsString('remove+set isId=true gebi=div', $aot);
    }

    public function testParseHelperDetectsFullHtmlDocumentWithoutId(): void
    {
        $this->assertTrue(
            \PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper::isFullHtmlDocumentArgv(
                '<!DOCTYPE html><html><body></body></html>'
            )
        );
        $this->assertFalse(
            \PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper::isFullHtmlDocumentArgv('<div>x</div>')
        );
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
        $bin = sys_get_temp_dir().'/dom_import_lh_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
