<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_case() runtime UTF-8 via NestedJIT convertCaseArgv (#34280 / #34284).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_case)
 *
 * @group llvm
 * @group aot
 */
final class MbConvertCaseRuntimeAotTest extends TestCase
{
    public function testAotRuntimeUpperLowerMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_convert_case_runtime_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testAotRuntimeTitleMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_convert_case_title_runtime_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testAotRuntimeTitleEncodingMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_convert_case_title_runtime_encoding_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testAotRuntimeTitleUnicodeMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_convert_case_title_unicode_runtime_aot.php';
        $this->assertSame($this->runVm($src), $this->runAot($src));
    }

    public function testLoweringUsesTitleArgvNotAsciiTitlePeel(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbConvertCase.php');
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbConvertCaseJitHelper.php');
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbConvertCaseRuntime.php');
        $this->assertStringContainsString('JitMbCase::invokeStrtoupper', $jit);
        $this->assertStringContainsString('MbConvertCaseRuntime::titleHelper', $jit);
        $this->assertStringContainsString('assertEncodingHelper', $jit);
        $this->assertStringContainsString('titleArgv', $helper);
        $this->assertStringContainsString('assertEncodingArgv', $helper);
        $this->assertStringContainsString('Argument #3', $helper);
        $this->assertStringContainsString('0x430', $helper);
        $this->assertStringContainsString('0x3B1', $helper);
        $this->assertStringContainsString('TITLE_LOGICAL', $runtime);
        $this->assertStringContainsString('ASSERT_ENCODING_LOGICAL', $runtime);
        $this->assertStringNotContainsString('transformAllAscii', $jit);
        $this->assertStringNotContainsString('asciiTitleRuntime', $jit);
        $this->assertStringNotContainsString('encoding must be a string literal', $jit);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_convert_case.c');
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
        $bin = sys_get_temp_dir().'/mb_convert_case_'.getmypid().'_'.md5($src);
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
