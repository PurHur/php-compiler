<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg()/mb_eregi() by-ref $regs (#35297 leftover of #33811).
 *
 * @see php-src ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_ereg)
 *
 * @group llvm
 * @group aot
 */
final class MbEregRegs35297AotTest extends TestCase
{
    public function testAotLiteralRegsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_ereg_regs_aot.php');
    }

    public function testAotRuntimeRegsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_ereg_regs_runtime_aot.php');
    }

    public function testRegsLoweringWired(): void
    {
        $root = dirname(__DIR__, 2);
        $fold = (string) file_get_contents($root.'/ext/mbstring/JitMbEregSearch.php');
        $this->assertStringContainsString('writeEregRegistersFold', $fold);
        $rt = (string) file_get_contents($root.'/ext/mbstring/JitMbEreg.php');
        $this->assertStringContainsString('writeEregRegistersRuntime', $rt);
        $this->assertStringContainsString('lastRegistersHelper', $rt);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
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
        $bin = sys_get_temp_dir().'/mb_ereg_regs_35297_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
