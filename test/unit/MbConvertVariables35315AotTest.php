<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_convert_variables() NestedJIT + by-ref writeback (#35315 leftover of #4572).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_variables)
 *
 * @group llvm
 * @group aot
 */
final class MbConvertVariables35315AotTest extends TestCase
{
    public function testVmRuntimeFromArrayMatchesZend(): void
    {
        $src = __DIR__.'/../repro/aot_mb_convert_variables_runtime_from_array.php';
        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $this->assertSame($zend, $vm);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/issue_35315_mb_convert_variables_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testAotRuntimeFromArrayMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_convert_variables_runtime_from_array.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testAotRuntimeFromArrayWithHelperCacheMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_convert_variables_runtime_from_array.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src, helperRuntimeCache: true);
        $this->assertSame($zend, $aot);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbConvertVariablesJitHelper.php');
        $this->assertStringContainsString('function convertStringArgv', $helper);
        $this->assertStringContainsString('function detectFromArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbConvertVariablesRuntime.php');
        $this->assertStringContainsString('convertStringHelper', $runtime);
        $this->assertStringContainsString('HELPER_BUNDLE', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbConvertVariables.php');
        $this->assertStringContainsString('MbConvertVariablesRuntime::convertStringHelper', $jit);
        $this->assertStringContainsString('MbConvertVariablesRuntime::detectHelper', $jit);
        $this->assertStringContainsString('MbConvertEncodingFromListLlvm::convert', $jit);
        $this->assertStringContainsString('MbConvertVariablesLlvm::convertArrayInPlace', $jit);
        $this->assertStringContainsString('MbConvertVariablesFromListLlvm::buildFromCsv', $jit);
        $this->assertStringNotContainsString(
            'array $from_encoding is not lowered for JIT/AOT runtime',
            $jit
        );
        $src = (string) file_get_contents($root.'/ext/mbstring/mb_convert_variables.php');
        $this->assertStringContainsString('JitMbConvertVariables::invoke', $src);
        $this->assertStringNotContainsString(
            'mb_convert_variables() is not lowered for JIT/AOT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_convert_variables.c');
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1',
            $out,
            $rc
        );
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runPhp(string $src): string
    {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src, bool $helperRuntimeCache = false): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/phpc_mcv_35315_'.getmypid();
        @unlink($bin);
        $env = $helperRuntimeCache
            ? 'env PHP_COMPILER_HELPER_RUNTIME_O=1 '
            : 'env PHP_COMPILER_HELPER_RUNTIME_O=0 ';
        $compile = $env
            .escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, implode("\n", $cout));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }
}
