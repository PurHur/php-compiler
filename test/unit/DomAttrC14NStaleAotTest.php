<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT C14N after setAttribute/removeAttribute must match Zend (#32981).
 *
 * @group llvm
 */
final class DomAttrC14NStaleAotTest extends TestCase
{
    public function testSetAndRemoveAttributeC14NMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_32981_dom_attr_c14n_stale.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('set=<r a="1" b="2"></r>', $aot);
        $this->assertStringContainsString('remove=<r b="2"></r>', $aot);
        $this->assertStringContainsString('rewrite=<r a="9"></r>', $aot);
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
        $bin = sys_get_temp_dir().'/dom_attr_c14n_'.getmypid();
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
