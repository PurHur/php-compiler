<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: insertBefore/replaceChild(Attr) must throw like Zend, not SIGSEGV (#33587).
 *
 * @group llvm
 */
final class DomInsertReplaceAttr33587AotTest extends TestCase
{
    public function testInsertBeforeAttrThrowsError(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33587_dom_insertbefore_attr_aot.php',
            'Error:Cannot add newnode as the previous sibling of refnode'
        );
    }

    public function testReplaceChildAttrThrowsHierarchyRequest(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_33587_dom_replacechild_attr_aot.php',
            'DOMException:Hierarchy Request Error'
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
        $bin = tempnam(sys_get_temp_dir(), 'phpc33587_');
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
