<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT C14N after tree mutation must match Zend — not the loadXML fold (#32972 / #34862).
 *
 * @group llvm
 */
final class DomC14NAfterMutationAotTest extends TestCase
{
    public function testC14NAfterMutationsMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_32972_dom_c14n_after_mutation.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('append=<r><c>hi</c><z></z></r>', $aot);
        $this->assertStringContainsString('insert=<r><z></z><c>hi</c></r>', $aot);
        $this->assertStringContainsString('replace=<r><z></z></r>', $aot);
        $this->assertStringContainsString('remove=<r></r>', $aot);
        $this->assertStringContainsString('plain=<r a="1"><c></c></r>', $aot);
        $this->assertStringContainsString('move=<r><b></b><a></a></r>', $aot);
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
        $bin = sys_get_temp_dir().'/dom_c14n_mut_'.getmypid();
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
