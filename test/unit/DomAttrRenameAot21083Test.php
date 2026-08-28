<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Dom\Attr::rename() + getAttribute after rekey (#21083).
 *
 * @group llvm
 */
final class DomAttrRenameAot21083Test extends TestCase
{
    public function testAttrRenameMatchesZend(): void
    {
        $src = __DIR__.'/../repro/maintainer_gap_dom_attr_rename.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dom_attr_rename_'.getmypid().'_'.md5($src);
        $cmd = 'PHP_COMPILER_PROFILE=8.4 '.escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($root.'/bin/compile.php')
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
