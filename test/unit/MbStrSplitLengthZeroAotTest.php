<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_str_split() length <= 0 throws catchable ValueError (re maintainer_gap_mb_str_split).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_split)
 *
 * @group llvm
 * @group aot
 */
final class MbStrSplitLengthZeroAotTest extends TestCase
{
    public function testAotLengthZeroMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_str_split_length_zero.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testMaintainerGapReproMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/maintainer_gap_mb_str_split.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testLoweringEmitsLengthGuard(): void
    {
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/ext/mbstring/JitMbStrSplit.php');
        $this->assertStringContainsString('emitLengthGuard', $jit);
        $this->assertStringContainsString('must be greater than 0', $jit);
        $this->assertStringContainsString('emitBranchOrAbortOnValueErrorFailure', $jit);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
        }
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_str_split_len0_'.getmypid().'_'.md5($src);
        $cache = sys_get_temp_dir().'/mb_str_split_len0_hr_'.getmypid();
        @mkdir($cache, 0777, true);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, implode("\n", $compileOut));
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
