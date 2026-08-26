<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_encoding_aliases() runtime encoding via NestedJIT (#35216 leftover of #30795).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_encoding_aliases)
 *
 * @group llvm
 * @group aot
 */
final class MbEncodingAliasesRuntimeEncodingAotTest extends TestCase
{
    public function testAotRuntimeEncodingMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_encoding_aliases_runtime_encoding.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
        $this->assertStringContainsString('utf8=utf8', $vm);
        $this->assertStringContainsString(
            'bad_enc=mb_encoding_aliases(): Argument #1 ($encoding) must be a valid encoding, "nope" given',
            $vm
        );
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbEncodingAliasesJitHelper.php');
        $assertHelper = (string) file_get_contents($root.'/ext/mbstring/MbEncodingAliasesAssertJitHelper.php');
        $this->assertStringContainsString('function aliasesJoinedArgv', $helper);
        $this->assertStringContainsString('EMPTY_ALIASES', $helper);
        $this->assertStringNotContainsString('CharsetEngine::', $helper);
        $this->assertStringNotContainsString('CharsetEngine::', $assertHelper);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*(?:\\\\)?MbstringEncodingRegistry::/m',
            $helper
        );
        $this->assertStringContainsString('function assertEncodingArgv', $assertHelper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbEncodingAliasesRuntime.php');
        $this->assertStringContainsString('aliasesHelper', $runtime);
        $this->assertStringContainsString('MbEncodingAliasesAssertJitHelper', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbEncodingRegistry.php');
        $this->assertStringContainsString('MbEncodingAliasesRuntime', $jit);
        $this->assertStringNotContainsString(
            'encoding must be a compile-time string in this compiler build',
            $jit
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_encoding_aliases.c');
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
        $bin = sys_get_temp_dir().'/mb_enc_aliases_'.getmypid().'_'.md5($src);
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
