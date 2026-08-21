<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: insertBefore/replaceChild(Attr) throw like Zend — no SIGSEGV (#33587).
 *
 * @group llvm
 */
final class DomInsertBeforeReplaceChildAttr33587AotTest extends TestCase
{
    public function testInsertBeforeAndReplaceChildAttr(): void
    {
        $src = __DIR__.'/../repro/issue_33587_dom_insertbefore_attr_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('Cannot add newnode as the previous sibling of refnode', $aot);
        $this->assertStringContainsString('Hierarchy Request Error', $aot);
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
        $bin = sys_get_temp_dir().'/dom_ib_rc_attr_33587_'.getmypid();
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
