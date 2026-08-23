<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: hash_copy() under thin standalone must clone mirrored props without NestedJIT (#34293).
 *
 * @see php-src ext/hash/hash.c PHP_FUNCTION(hash_copy)
 *
 * @group llvm
 * @group aot
 */
final class HashCopyThinAot34293Test extends TestCase
{
    public function testAotHashCopyMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/hash_copy_thin_aot_34293.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testAotLegacyCopyReproMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_hash_copy.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testCopyLoweringSkipsNestedJitOnThinAot(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/hash/JitHashContext.php');
        $this->assertStringContainsString('isThinStandaloneAotMain()', $jit);
        $this->assertStringContainsString('HashContextJitHelper::copy segfaults', $jit);
        $this->assertStringContainsString('#34293', $jit);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/hash_copy.c');
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
        $bin = sys_get_temp_dir().'/hash_copy_34293_'.getmypid().'_'.md5($src);
        $cache = sys_get_temp_dir().'/hash_copy_34293_hr_'.getmypid();
        @mkdir($cache, 0777, true);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
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
