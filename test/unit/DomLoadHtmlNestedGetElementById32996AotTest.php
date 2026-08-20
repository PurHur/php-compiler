<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT loadHTML of full html/body docs must find nested ids (#32996).
 *
 * @group llvm
 */
final class DomLoadHtmlNestedGetElementById32996AotTest extends TestCase
{
    public function testNestedLoadHtmlGetElementByIdMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_32996_dom_loadhtml_nested_getelementbyid.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame("ok\nhi", $aot);
    }

    public function testParseHelperFindsNestedId(): void
    {
        $parsed = \PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper::parseArgv(
            '<html><body><p id="x">hi</p></body></html>'
        );
        $this->assertNotNull($parsed);
        $this->assertSame('p', $parsed['tag']);
        $this->assertSame('x', $parsed['id']);
        $this->assertSame('hi', $parsed['text']);

        $byId = \PHPCompiler\ext\dom\DomParseSimpleHtmlJitHelper::parseIdElementArgv(
            '<html><body><p id="a">1</p><p id="b">2</p></body></html>',
            'b'
        );
        $this->assertNotNull($byId);
        $this->assertSame('b', $byId['id']);
        $this->assertSame('2', $byId['text']);
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
        $bin = sys_get_temp_dir().'/dom_lh_nested_'.getmypid();
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
