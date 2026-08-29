<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode preserves DTD ATTLIST ID for isId/getElementById on target (#21102).
 *
 * php-src: ext/dom/document.c — DOMDocument::importNode / xmlCopyProp atype
 *
 * @group llvm
 * @group aot
 */
final class DomImportNodeDtdId21102AotTest extends TestCase
{
    public function testImportNodeDtdIdGetElementByIdMatchesZend(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/maintainer_gap_dom_importnode_dtd_id.php'
        );
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, 'AOT must match Zend for '.basename($src));
    }

    private function runPhp(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_import_dtd_id_21102_'.getmypid().'_'.md5($src);
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
