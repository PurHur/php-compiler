<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: removeChild of non-child throws Not Found like Zend (#33599).
 *
 * @group llvm
 */
final class DomRemoveChildNotChild33599AotTest extends TestCase
{
    public function testRemoveChildNonChildThrowsNotFound(): void
    {
        $src = __DIR__.'/../repro/issue_33599_dom_removechild_not_child_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('Not Found Error', $aot);
        $this->assertStringContainsString('sibLen=1', $aot);
        $this->assertStringNotContainsString('NO THROW', $aot);
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
        $bin = sys_get_temp_dir().'/dom_rm_not_child_33599_'.getmypid();
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
