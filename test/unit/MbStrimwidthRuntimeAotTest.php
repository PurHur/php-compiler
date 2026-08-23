<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: mb_strimwidth() runtime start/width via callHelper (#34264).
 *
 * @see php-src ext/mbstring/mbstring.c PHP_FUNCTION(mb_strimwidth)
 *
 * @group llvm
 * @group aot
 */
final class MbStrimwidthRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/mb_strimwidth_runtime_aot.php';
        $env = [];
        $vm = $this->runPhp($src, $env);
        $aot = $this->runAot($src, $env);
        $this->assertSame($vm, $aot);
    }

    public function testLoweringUsesCallHelper(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/mbstring/JitMbStrwidth.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $jit);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $jit);
        $this->assertStringContainsString('strimwidthFunction', $jit);
        $helper = (string) file_get_contents($root.'/ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringContainsString('Snapshot before icmp', $helper);
        $this->assertStringNotContainsString('return VmMbstring::strimwidth', $helper);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/mb_strimwidth.c');
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
        $bin = sys_get_temp_dir().'/mb_strim_'.getmypid().'_'.md5($src);
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
