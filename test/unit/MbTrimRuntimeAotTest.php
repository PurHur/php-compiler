<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_trim/mb_ltrim/mb_rtrim via MbTrimJitHelper (#34379 leftover of #5957/#23883).
 *
 * Docker reference PHP is 8.2 (no mb_trim) — compare AOT vs VM under PROFILE=8.4
 * (peer {@see MbUcfirstLcfirstRuntimeAotTest}).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_trim)
 *
 * @group llvm
 * @group aot
 */
final class MbTrimRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/aot_mb_trim_runtime.php';
        $env = ['PHP_COMPILER_PROFILE' => '8.4'];
        $vm = $this->runPhp($src, $env);
        $aot = $this->runAot($src, $env);
        $this->assertSame($vm, $aot);
    }

    public function testHelperAndLoweringPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbTrimJitHelper.php');
        $this->assertStringContainsString('function trimArgv', $helper);
        $runtime = (string) file_get_contents($root.'/lib/JIT/Builtin/MbTrimRuntime.php');
        $this->assertStringContainsString('trimHelper', $runtime);
        $this->assertStringContainsString('MbTrimJitHelper::trimArgv', $runtime);
        $src = (string) file_get_contents($root.'/ext/mbstring/JitMbTrim.php');
        $this->assertStringContainsString('MbTrimRuntime::ensureLinked', $src);
        $this->assertStringNotContainsString(
            'is not lowered for JIT/AOT in this compiler build',
            $src
        );
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_trim.c');
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/phpc_mb_trim.c');
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
        $bin = sys_get_temp_dir().'/mb_trim_'.getmypid().'_'.md5($src);
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

        return '' === implode(' ', $parts) ? '' : 'env '.implode(' ', $parts).' ';
    }
}
