<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode HTML id reindex + setIdAttribute must not leak across documents (#20830).
 *
 * php-src: ext/dom/document.c — DOMDocument::importNode
 * php-src: ext/dom/node.c — ID table / setIdAttribute vs xmlCopyProp atype
 *
 * @group llvm
 * @group aot
 */
final class DomImportNodeGetElementByIdReindex20830AotTest extends TestCase
{
    public function testImportNodeGetElementByIdReindexMatchesZend(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/maintainer_gap_dom_importnode_getelementbyid_reindex.php'
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
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_import_gebi_20830_'.getmypid().'_'.md5($src);
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
