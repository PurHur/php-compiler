<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ereg_replace()/mb_eregi_replace() runtime args via MbEregJitHelper (#34389 leftover of #33765/#33656).
 *
 * @see php-src ext/mbstring/php_mbregex.c PHP_FUNCTION(mb_ereg_replace)
 *
 * @group llvm
 * @group aot
 */
final class MbEregReplace34389AotTest extends TestCase
{
    public function testAotRuntimeReplaceMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34389_mb_ereg_replace_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/ext/mbstring/MbEregJitHelper.php');
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_ereg_replace.php');
        $this->assertStringContainsString('JitMbEreg::invokeReplace', $src);
        $this->assertStringNotContainsString('is not lowered for JIT/AOT', $src);
        $eregi = (string) file_get_contents($root.'/ext/mbstring/mb_eregi_replace.php');
        $this->assertStringContainsString('JitMbEreg::invokeReplace', $eregi);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_ereg_replace.c');
        $this->assertFileDoesNotExist($root.'/runtime/mb_ereg_replace.c');
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
        $bin = sys_get_temp_dir().'/mb_ereg_replace_34389_'.getmypid().'_'.md5($src);
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
