<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: iconv_strlen/strpos/strrpos runtime args via callHelper (#34277).
 *
 * @see php-src ext/iconv/iconv.c PHP_FUNCTION(iconv_strlen), iconv_strpos, iconv_strrpos
 *
 * @group llvm
 * @group aot
 */
final class IconvSearchRuntimeAotTest extends TestCase
{
    public function testAotRuntimeMatchMatchesVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/iconv_search_runtime_aot.php';
        $vm = $this->runPhp($src, []);
        $aot = $this->runAot($src, []);
        $this->assertSame($vm, $aot);
    }

    public function testLoweringUsesCallHelperForSearchFamily(): void
    {
        $root = dirname(__DIR__, 2);
        $jit = (string) file_get_contents($root.'/ext/iconv/JitIconvString.php');
        $this->assertStringContainsString('StringIconvSubstr::strlenHelper', $jit);
        $this->assertStringContainsString('StringIconvSubstr::strposHelper', $jit);
        $this->assertStringContainsString('StringIconvSubstr::strrposHelper', $jit);
        $this->assertStringContainsString('StringStrpos::boxFoundOffset', $jit);
        $helper = (string) file_get_contents($root.'/ext/iconv/IconvStringJitHelper.php');
        $this->assertStringContainsString('function strlenArgv', $helper);
        $this->assertStringContainsString('function strposArgv', $helper);
        $this->assertStringContainsString('function strrposArgv', $helper);
        $this->assertStringNotContainsString('return VmIconv', $helper);
        $this->assertFileDoesNotExist($root.'/lib/AOT/runtime/iconv_strpos.c');
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
        $bin = sys_get_temp_dir().'/iconv_search_'.getmypid().'_'.md5($src);
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

        return $parts === [] ? '' : 'env '.implode(' ', $parts).' ';
    }
}
