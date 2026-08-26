<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_trim()/mb_ltrim()/mb_rtrim() runtime encoding via MbTrimJitHelper (#35199 leftover of #34379).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_trim)
 *
 * @group llvm
 * @group aot
 */
final class MbTrimRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeEncodingMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_trim_runtime_encoding.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbTrimEncodingJitHelper.php');
        $this->assertStringContainsString('function assertEncodingArgv', $helper);
        $this->assertStringContainsString('Argument #3', $helper);
        $trimHelper = (string) file_get_contents($root.'/ext/mbstring/MbTrimJitHelper.php');
        $this->assertStringContainsString('function trimDefault', $trimHelper);
        $this->assertStringContainsString('function rtrimDefault', $trimHelper);
        $this->assertStringNotContainsString('function assertEncodingArgv', $trimHelper);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbTrim.php');
        $this->assertStringContainsString('encodingPtr', $jit);
        $this->assertStringContainsString('tryFoldableStringWithEncodingGate', $jit);
        $this->assertStringContainsString('assertEncodingHelper', $jit);
        $this->assertStringContainsString(
            'encoding must be a string literal in this compiler build',
            $jit
        );
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbTrimRuntime.php');
        $this->assertStringContainsString('ASSERT_ENCODING_LOGICAL', $runtime);
        $this->assertStringContainsString('assertEncodingHelper', $runtime);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_trim.c');
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
        $bin = sys_get_temp_dir().'/mb_trim_enc_'.getmypid().'_'.md5($src);
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
