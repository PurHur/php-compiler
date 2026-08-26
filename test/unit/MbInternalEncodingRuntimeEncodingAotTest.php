<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_internal_encoding() runtime encoding via NestedJIT (#35221 leftover of #13100/#20014).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_internal_encoding)
 *
 * @group llvm
 * @group aot
 */
final class MbInternalEncodingRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeEncodingMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_internal_encoding_runtime.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
        $this->assertStringContainsString(
            'bad_enc=mb_internal_encoding(): Argument #1 ($encoding) must be a valid encoding, "nope" given',
            $vm
        );
        $this->assertStringContainsString("get_iso='ISO-8859-1'", $vm);
        $this->assertStringContainsString("after_bad='ASCII'", $vm);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbInternalEncodingJitHelper.php');
        $this->assertStringContainsString('function getArgv', $helper);
        $this->assertStringContainsString('function setArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbInternalEncodingRuntime.php');
        $this->assertStringContainsString('getHelper', $runtime);
        $this->assertStringContainsString('setHelper', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbInternalEncoding.php');
        $this->assertStringContainsString('MbInternalEncodingRuntime', $jit);
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_internal_encoding.php');
        $this->assertStringContainsString('JitMbInternalEncoding::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_internal_encoding() encoding must be a compile-time string in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_internal_encoding.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_internal_encoding.c');
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
        $bin = sys_get_temp_dir().'/mb_internal_enc_'.getmypid().'_'.md5($src);
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
