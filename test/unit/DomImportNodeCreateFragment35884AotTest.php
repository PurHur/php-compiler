<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: importNode(createDocumentFragment) copies children (#35884 leftover of #35871).
 *
 * @group llvm
 */
final class DomImportNodeCreateFragment35884AotTest extends TestCase
{
    public function testCreateFragmentImportMatchZend(): void
    {
        $src = __DIR__.'/../repro/aot_dom_importnode_create_fragment.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('type=11', $aot);
        $this->assertStringContainsString('xml=<a/>t', $aot);
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
        $bin = sys_get_temp_dir().'/dom_import_35884_'.getmypid().'_'.mt_rand(0, 99999);
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
