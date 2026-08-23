<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_str_pad() runtime length via JitNestedHelperCoerce (#34270 leftover of #6081).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_pad)
 *
 * @group llvm
 * @group aot
 */
final class MbStrPadRuntimeIntsAotTest extends TestCase
{
    public function testAotRuntimeIntsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_str_pad_runtime_ints_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testLoweringUsesNestedHelperCoerce(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbStrPad.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $jit);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $jit);
        $this->assertStringContainsString('strPadFunction', $jit);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringNotContainsString('return VmMbstring::strPad', $helper);
        $this->assertStringContainsString('NestedJIT-safe peel', $helper);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_str_pad.c');
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 '
            .escapeshellarg(PHP_BINARY).' '
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
        $bin = sys_get_temp_dir().'/mb_str_pad_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_PROFILE=8.3 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
