<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: insertBefore(Attr) must throw Error, not SIGSEGV (#33587).
 *
 * Combined repro also covers replaceChild(Attr) — see
 * DomInsertBeforeReplaceChildAttr33587AotTest.
 *
 * @group llvm
 */
final class DomInsertReplaceAttr33587AotTest extends TestCase
{
    public function testInsertBeforeAttrThrowsError(): void
    {
        $out = $this->runAot(__DIR__.'/../repro/issue_33587_dom_insertbefore_attr_aot.php');
        $zend = $this->runPhp(__DIR__.'/../repro/issue_33587_dom_insertbefore_attr_aot.php');
        $this->assertSame($zend, $out);
        $this->assertStringContainsString('Cannot add newnode as the previous sibling of refnode', $out);
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
