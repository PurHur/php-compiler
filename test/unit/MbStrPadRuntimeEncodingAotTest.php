<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_str_pad() runtime encoding via MbStrwidthJitHelper (#35187 leftover of #34270).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_pad)
 *
 * @group llvm
 * @group aot
 */
final class MbStrPadRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeEncodingMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_str_pad_runtime_encoding.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringContainsString('function strPad', $helper);
        $this->assertStringContainsString('function assertEncodingArgv', $helper);
        $this->assertStringContainsString('isByteOrientedEncoding', $helper);
        $this->assertStringContainsString('Argument #5', $helper);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbStrPad.php');
        $this->assertStringContainsString('encodingPtr', $jit);
        $this->assertStringContainsString('assertEncodingHelper', $jit);
        $this->assertStringNotContainsString(
            'encoding must be a string literal in this compiler build',
            $jit
        );
        $this->assertStringNotContainsString(
            "compileTimeString ?? 'UTF-8'",
            $jit
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_str_pad.c');
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_str_pad_enc_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 PHP_COMPILER_HELPER_RUNTIME_O=0 '
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
