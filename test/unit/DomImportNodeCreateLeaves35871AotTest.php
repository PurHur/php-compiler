<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode(createComment/CDATA/PI/DocumentFragment) must copy the leaf
 * (php-src xmlDocCopyNode; leftover of #35098 — #35871).
 *
 * @group llvm
 */
final class DomImportNodeCreateLeaves35871AotTest extends TestCase
{
    public function testCreateCommentImportMatchZend(): void
    {
        $this->assertReproMatchesZend(__DIR__.'/../repro/aot_dom_importnode_create_comment.php');
    }

    public function testCreateCdataPiImportMatchZend(): void
    {
        $this->assertReproMatchesZend(__DIR__.'/../repro/aot_dom_importnode_create_cdata_pi.php');
    }

    public function testCreateFragmentImportMatchZend(): void
    {
        $this->assertReproMatchesZend(__DIR__.'/../repro/aot_dom_importnode_create_fragment.php');
    }

    public function testLoadXmlFirstChildLeafStillMatchZend(): void
    {
        $this->assertReproMatchesZend(__DIR__.'/../repro/aot_dom_importnode_leaf_nodes.php');
    }

    private function assertReproMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot, basename($src));
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
        $bin = sys_get_temp_dir().'/dom_import_35871_'.getmypid().'_'.mt_rand(0, 99999);
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
