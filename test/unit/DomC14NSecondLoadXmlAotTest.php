<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: C14N after a second loadXML must use the receiver's document (#32978).
 *
 * @group llvm
 */
final class DomC14NSecondLoadXmlAotTest extends TestCase
{
    public function testSecondLoadXmlC14NMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_32978_dom_c14n_second_loadxml.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('<r><c></c></r>', $aot);
    }

    public function testImportNodeAppendC14NMatchesZend(): void
    {
        $src = __DIR__.'/../repro/issue_32978_dom_c14n_import_second.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('<r><x><y></y></x></r>', $aot);
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
        $bin = sys_get_temp_dir().'/dom_c14n_32978_'.getmypid().'_'.mt_rand(1000, 9999);
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
