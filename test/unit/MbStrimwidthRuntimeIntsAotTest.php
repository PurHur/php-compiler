<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_strimwidth() runtime start/width via JitNestedHelperCoerce (#34264 leftover of #3495).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_strimwidth)
 *
 * @group llvm
 * @group aot
 */
final class MbStrimwidthRuntimeIntsAotTest extends TestCase
{
    public function testAotRuntimeIntsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_strimwidth_runtime_ints_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testLoweringUsesNestedHelperCoerce(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbStrwidth.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $jit);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $jit);
        $this->assertStringContainsString('strimwidthFunction', $jit);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strimwidth.c');
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
        $bin = sys_get_temp_dir().'/mb_strimwidth_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
