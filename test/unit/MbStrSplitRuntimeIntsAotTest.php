<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_str_split() runtime length via NestedJIT peel (#34278 leftover of #26870).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_split)
 *
 * @group llvm
 * @group aot
 */
final class MbStrSplitRuntimeIntsAotTest extends TestCase
{
    public function testAotRuntimeIntsMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_str_split_runtime_ints_aot.php';
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
        $this->assertStringContainsString("a,b,c", $aot);
        $this->assertStringContainsString("üb,er", $aot);
    }

    public function testLoweringUsesNestedHelperCoerceWithoutVmMbstring(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbStrSplit.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $jit);
        $this->assertStringContainsString('MbStrSplitRuntime::helperFunction', $jit);
        $this->assertStringContainsString('JitStrictIntArg::lower', $jit);
        $this->assertStringNotContainsString('lowerIntBuiltinArgForCaller', $jit);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbStrSplitRuntime.php');
        $this->assertStringContainsString('strSplitRuntimeArgv', $runtime);
        $this->assertMatchesRegularExpression('/ensureCompiled\([\s\S]*?true\s*\)/', $runtime);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbStrSplitJitHelper.php');
        $this->assertStringNotContainsString('VmMbstring::', $helper);
        $this->assertStringNotContainsString('VmString::', $helper);
        $this->assertStringNotContainsString('new HashTable', $helper);
        $this->assertStringNotContainsString('use PHPCompiler\\VM\\HashTable', $helper);
        $this->assertStringContainsString('strSplitRuntimeArgv', $helper);
        $this->assertStringContainsString(': array', $helper);
        $this->assertStringContainsString('($charIndex - $chunkStartChar) == $length', $helper);
        $this->assertStringContainsString('isset($string[$byteLen])', $helper);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_str_split.c');
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
        $bin = sys_get_temp_dir().'/mb_str_split_'.getmypid().'_'.md5($src);
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
