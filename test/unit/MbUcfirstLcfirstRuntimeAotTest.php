<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_ucfirst()/mb_lcfirst() runtime args via MbCaseJitHelper (#34259).
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
        $src = __DIR__.'/../repro/mb_ucfirst_lcfirst_runtime_aot.php';
        $env = ['PHP_COMPILER_PROFILE' => '8.4'];
        $vm = $this->runPhp($src, $env);
        $aot = $this->runAot($src, $env);
        $this->assertSame($vm, $aot);
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
        $this->assertStringContainsString('MbCaseRuntime::ensureLinked', $jit);
        $this->assertStringNotContainsString(
            'is not lowered for JIT/AOT in this compiler build',
            $jit
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_ucfirst.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_lcfirst.c');
    }

    /**
     * @param array<string, string> $env
     */
    private function runPhp(string $src, array $env): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = $this->envPrefix($env)
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

    /**
     * @param array<string, string> $env
     */
    private function runAot(string $src, array $env): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_ulc_'.getmypid().'_'.md5($src);
        $cmd = $this->envPrefix($env + ['PHP_COMPILER_HELPER_RUNTIME_O' => '0'])
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

    /**
     * @param array<string, string> $env
     */
    private function envPrefix(array $env): string
    {
        $parts = [];
        foreach ($env as $k => $v) {
            $parts[] = $k.'='.escapeshellarg($v);
        }

        return 'env '.implode(' ', $parts).' ';
    }
}
