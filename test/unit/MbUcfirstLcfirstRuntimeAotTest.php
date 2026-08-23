<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ucfirst()/mb_lcfirst() runtime args via MbCaseJitHelper (#34259 leftover of #27330).
 *
 * Host Zend is 8.2 (no mb_ucfirst) — compare AOT to VM under PHP_COMPILER_PROFILE=8.4.
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_ucfirst), PHP_FUNCTION(mb_lcfirst)
 *
 * @group llvm
 * @group aot
 */
final class MbUcfirstLcfirstRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesVm(__DIR__.'/../repro/mb_ucfirst_lcfirst_runtime_aot.php');
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbCaseJitHelper.php');
        $this->assertStringContainsString('function ucfirstArgv', $helper);
        $this->assertStringContainsString('function lcfirstArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbCaseRuntime.php');
        $this->assertStringContainsString('ucfirstHelper', $runtime);
        $this->assertStringContainsString('lcfirstHelper', $runtime);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbUcfirstLcfirst.php');
        $this->assertStringContainsString('MbCaseRuntime::ucfirstHelper', $jit);
        $this->assertStringContainsString('MbCaseRuntime::lcfirstHelper', $jit);
        $this->assertStringContainsString('JitStringBuiltinArg::lowerTrimFamilyString', $jit);
        // Runtime path must not refuse non-literal strings (the #34259 silent-NULL bug).
        $this->assertStringNotContainsString(
            "null === (\$args[0]->compileTimeString ?? null))",
            $jit
        );
        foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
            $src = (string) file_get_contents($root.'/ext/mbstring/'.$fn.'.php');
            $this->assertStringContainsString('JitMbUcfirstLcfirst::invoke', $src);
        }
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_ucfirst.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_lcfirst.c');
    }

    private function assertAotMatchesVm(string $src): void
    {
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame($vm, $aot);
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'env PHP_COMPILER_PROFILE=8.4 '
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
        $bin = sys_get_temp_dir().'/mb_ulcfirst_'.getmypid().'_'.md5($src);
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
