<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: setAttribute/setAttributeNS with string locals — METHODCALL argOperands (#35234).
 *
 * @group llvm
 */
final class DomMethodCallSetAttrLocals35234AotTest extends TestCase
{
    public function testSetAttributeAndSetAttributeNSLocalsMatchZend(): void
    {
        $src = __DIR__.'/../repro/issue_35234_methodcall_setattr_locals_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
        $this->assertStringContainsString('k="v"', $aot);
        $this->assertStringContainsString("1\n", $aot."\n");
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
        $bin = sys_get_temp_dir().'/dom_setattr_locals_35234_'.getmypid();
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
