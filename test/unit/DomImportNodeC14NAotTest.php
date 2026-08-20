<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode + appendChild must update C14N fold (#32987).
 *
 * @group llvm
 */
final class DomImportNodeC14NAotTest extends TestCase
{
    public function testImportNodeAppendC14NMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_32987_dom_importnode_c14n.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertSame(
            "<r><c></c></r>\n<r><c></c><x><y></y></x></r>",
            $aot
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
        $bin = sys_get_temp_dir().'/dom_import_c14n_'.getmypid();
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
