<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_kana() runtime encoding via MbConvertKanaJitHelper (#35193 leftover of #34294).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_kana)
 *
 * @group llvm
 * @group aot
 */
final class MbConvertKanaRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeEncodingMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_convert_kana_runtime_encoding.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
        $this->assertStringContainsString('bad_enc=mb_convert_kana(): Argument #3 ($encoding) must be a valid encoding, "nope" given', $vm);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbConvertKanaJitHelper.php');
        $this->assertStringContainsString('function assertEncodingArgv', $helper);
        $this->assertStringContainsString('Argument #3', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbConvertKanaRuntime.php');
        $this->assertStringContainsString('assertEncodingHelper', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbConvertKana.php');
        $this->assertStringContainsString('encodingPtr', $jit);
        $this->assertStringContainsString('tryFoldableStringModeWithEncodingGate', $jit);
        $this->assertStringContainsString('assertEncodingHelper', $jit);
        $this->assertStringNotContainsString(
            'encoding must be a string literal in this compiler build',
            $jit
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_convert_kana.c');
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
        $bin = sys_get_temp_dir().'/mb_convert_kana_enc_'.getmypid().'_'.md5($src);
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
